<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Config\SystemCompose;
use DockerCli\Hook\CommandHookRunner;
use DockerCli\Project\ConfigurableServicesRestarter;
use DockerCli\Project\DataInitializer;
use DockerCli\Project\DedicatedDatabaseComposeRenderer;
use DockerCli\Project\MysqlDumpLoader;
use DockerCli\Project\OpenRestyHostRenderer;
use DockerCli\Project\PhpLanguageVersion;
use DockerCli\Project\PostgresDumpLoader;
use DockerCli\Project\ProjectRegistry;

use function DockerCli\Util\join_path;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

final class ProjectUpdateCommand extends AbstractCommand
{
    public function __construct(
        private readonly ?ProjectRegistry $registry = null,
        private readonly ?CommandContext $context = null,
        private readonly ?CommandHookRunner $hookRunner = null,
    ) {
        parent::__construct("project:update");
        $this->setDescription("Изменить зарегистрированный проект.");
        $this->addOption("name", null, InputOption::VALUE_REQUIRED, "Новое имя проекта.");
        $this->addOption("language", null, InputOption::VALUE_REQUIRED, "Код языка проекта.");
        $this->addOption(
            "language-version",
            null,
            InputOption::VALUE_REQUIRED,
            "Версия языка проекта: 8.2, 8.3, 8.4 или 8.5.",
        );
        $this->addOption("framework", null, InputOption::VALUE_REQUIRED, "Код фреймворка проекта.");
        $this->addOption("external-port", null, InputOption::VALUE_REQUIRED, "Порт внешнего сервиса.");
        $this->addOption(
            "dedicated-db",
            null,
            InputOption::VALUE_REQUIRED,
            "Выделенные СУБД: mysql, postgres или false для общих " . "системных инстансов.",
        );
        $this->addOption("location-mysql", null, InputOption::VALUE_REQUIRED, "Каталог данных выделенного MySQL.");
        $this->addOption(
            "location-postgres",
            null,
            InputOption::VALUE_REQUIRED,
            "Каталог данных выделенного PostgreSQL.",
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getOption("name");
        $language = $input->getOption("language");
        $languageVersion = $input->getOption("language-version");
        $framework = $input->getOption("framework");
        $externalPort = $input->getOption("external-port");
        $dedicatedOption = $input->getOption("dedicated-db");
        if (
            $name === null &&
            $language === null &&
            $languageVersion === null &&
            $framework === null &&
            $dedicatedOption === null &&
            $externalPort === null
        ) {
            $this->writeMessage(
                $output,
                "<comment>Не указаны изменения: используйте --name, " .
                    "--language, --language-version, --framework, --external-port или --dedicated-db.</comment>",
            );
            return Command::SUCCESS;
        }
        if ($name !== null && (!is_string($name) || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name) !== 1)) {
            $this->writeMessage(
                $output,
                "<error>Имя проекта должно содержать строчные " . "латинские буквы, цифры и дефисы.</error>",
            );
            return Command::FAILURE;
        }
        if (
            ($language !== null && $language !== "php") ||
            ($framework !== null &&
                !in_array($framework, ["", "symfony", "laravel", "bitrix", "bitrix24", "external"], true))
        ) {
            $this->writeMessage($output, "<error>Указан неподдерживаемый язык или фреймворк.</error>");
            return Command::FAILURE;
        }
        if ($languageVersion !== null && !PhpLanguageVersion::isSupported($languageVersion)) {
            $this->writeMessage($output, "<error>Версия PHP должна быть одной из: 8.2, 8.3, 8.4, 8.5.</error>");
            return Command::FAILURE;
        }

        $registry = $this->registry ?? new ProjectRegistry();
        $oldName = $registry->projectNameFromContext();
        if ($oldName === null || !$registry->hasProject($oldName)) {
            $this->writeMessage(
                $output,
                "<error>Запустите команду в директории " . "зарегистрированного проекта.</error>",
            );
            return Command::FAILURE;
        }
        $newName = is_string($name) ? $name : $oldName;
        if (
            $newName !== $oldName &&
            ($registry->hasProject($newName) || file_exists($registry->projectDirectory($newName)))
        ) {
            $this->writeMessage($output, sprintf('<error>Проект "%s" уже зарегистрирован.</error>', $newName));
            return Command::FAILURE;
        }

        $config = $registry->readProjectConfig($oldName);
        $dedicated = $this->resolveDedicatedDatabases($input, $config, $oldName, $output);
        if ($dedicated === null) {
            return Command::INVALID;
        }
        $project = $config["data"]["project"] ?? null;
        $root = is_array($project) ? $project["root"] ?? null : null;
        $localFile = is_string($root) ? join_path($root, ".docker-cli", "project.yaml") : "";
        if (!is_array($project) || !is_string($root) || $root === "" || !is_file($localFile)) {
            $this->writeMessage($output, "<error>Конфигурация проекта повреждена.</error>");
            return Command::FAILURE;
        }
        $resultingFramework =
            $framework !== null ? ($framework !== "" ? $framework : null) : ($project["framework"] ?? null);
        if ($externalPort !== null && $resultingFramework !== "external") {
            $this->writeMessage($output, "<error>Опцию --external-port можно использовать только с external.</error>");
            return Command::INVALID;
        }
        if ($externalPort !== null && !$this->isValidPort($externalPort)) {
            $this->writeMessage($output, "<error>Опция --external-port должна быть числом от 1 до 65535.</error>");
            return Command::INVALID;
        }
        $localConfig = Yaml::parseFile($localFile);
        if (!is_array($localConfig) || !is_array($localConfig["data"]["project"] ?? null)) {
            $this->writeMessage($output, "<error>Локальная конфигурация проекта повреждена.</error>");
            return Command::FAILURE;
        }

        $hookArguments = $input instanceof ArgvInput ? $input->getRawTokens(true) : [];
        $beforeHookCode = ($this->hookRunner ?? new CommandHookRunner())->run(
            "project:update",
            "before",
            $hookArguments,
        );
        if ($beforeHookCode !== Command::SUCCESS) {
            return $beforeHookCode;
        }

        $originalConfig = $config;
        $migrationDrivers = array_values(
            array_filter(
                ["mysql", "postgres"],
                fn (string $driver): bool => in_array($driver, $dedicated["drivers"], true) !==
                    (($config["data"]["databases"][$driver]["hostname"] ?? null) === "docker-cli-$driver-$oldName") ||
                    ($newName !== $oldName && in_array($driver, $dedicated["drivers"], true)),
            ),
        );
        $snapshots = $this->dumpDatabases($config, $migrationDrivers, $output);
        if ($snapshots === null) {
            return Command::FAILURE;
        }
        $routingChanged =
            $newName !== $oldName ||
            ($language !== null && $language !== ($project["language"] ?? null)) ||
            ($languageVersion !== null &&
                $languageVersion !== ($project["language_version"] ?? PhpLanguageVersion::default())) ||
            ($framework !== null && ($framework !== "" ? $framework : null) !== ($project["framework"] ?? null));
        $routingChanged = $routingChanged ||
            ($externalPort !== null && (int) $externalPort !== ($project["external_port"] ?? null));
        $config["data"]["project"]["name"] = $newName;
        $localConfig["data"]["project"]["name"] = $newName;
        if (is_string($language)) {
            $config["data"]["project"]["language"] = $language;
        }
        if (is_string($languageVersion)) {
            $config["data"]["project"]["language_version"] = $languageVersion;
        }
        if (is_string($framework)) {
            $config["data"]["project"]["framework"] = $framework !== "" ? $framework : null;
            if ($framework !== "external") {
                unset($config["data"]["project"]["external_port"]);
            }
        }
        if ($externalPort !== null) {
            $config["data"]["project"]["external_port"] = (int) $externalPort;
        }
        foreach (["mysql", "postgres"] as $driver) {
            if (!in_array($driver, $migrationDrivers, true)) {
                continue;
            }
            $config["data"]["databases"][$driver]["hostname"] = in_array($driver, $dedicated["drivers"], true)
                ? "docker-cli-$driver-$newName"
                : "docker-cli-$driver";
            unset($config["data"]["databases"][$driver]["location"]);
            if (isset($dedicated["locations"][$driver])) {
                $config["data"]["databases"][$driver]["location"] = $dedicated["locations"][$driver];
            }
        }
        $oldDirectory = $registry->projectDirectory($oldName);
        $newDirectory = $registry->projectDirectory($newName);
        $registry->writeProjectConfig($oldName, $config);
        file_put_contents($localFile, Yaml::dump($localConfig, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        if ($newName !== $oldName && !rename($oldDirectory, $newDirectory)) {
            $localConfig["data"]["project"]["name"] = $oldName;
            $registry->writeProjectConfig($oldName, $originalConfig);
            file_put_contents($localFile, Yaml::dump($localConfig, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
            $this->writeMessage($output, "<error>Не удалось переименовать директорию " . "проекта в реестре.</error>");
            return Command::FAILURE;
        }

        if ($migrationDrivers !== []) {
            (new DedicatedDatabaseComposeRenderer())->render();
            $code = $this->restoreDatabases($config, $newName, $migrationDrivers, $snapshots, $output);
            if ($code !== Command::SUCCESS) {
                return $code;
            }
            $this->removeOldInstances($originalConfig, $oldName, $migrationDrivers, $output);
            foreach ($snapshots as $snapshot) {
                if (is_dir($snapshot)) {
                    $this->removeDirectory($snapshot);
                }
            }
        }

        if ($routingChanged) {
            (new OpenRestyHostRenderer())->render();
            $restartCode = (new ConfigurableServicesRestarter())->restart($output);
            if ($restartCode !== Command::SUCCESS) {
                return $restartCode;
            }
        }
        ($this->context ?? CommandContext::fromEnvironment($this, $output))->addMessage(
            new Message(sprintf("Проект **%s** изменен.", $newName), notify: true),
        );
        return ($this->hookRunner ?? new CommandHookRunner())->run("project:update", "after", $hookArguments);
    }

    /** @return array{drivers:list<string>,locations:array<string,string>}|null */
    private function resolveDedicatedDatabases(
        InputInterface $input,
        array $config,
        string $name,
        OutputInterface $output,
    ): ?array {
        $option = $input->getOption("dedicated-db");
        $drivers =
            $option === null
                ? array_values(
                    array_filter(
                        ["mysql", "postgres"],
                        fn (string $driver): bool => ($config["data"]["databases"][$driver]["hostname"] ?? null) ===
                            "docker-cli-$driver-$name",
                    ),
                )
                : ($option === "false"
                    ? []
                    : array_values(array_unique(array_filter(array_map("trim", explode(",", (string) $option))))));
        if (array_diff($drivers, ["mysql", "postgres"]) !== []) {
            $this->writeMessage($output, "<error>Опция --dedicated-db поддерживает mysql, postgres или false.</error>");
            return null;
        }
        $locations = [];
        foreach (["mysql", "postgres"] as $driver) {
            $location = $input->getOption("location-" . $driver);
            if ($location === null) {
                continue;
            }
            if (!in_array($driver, $drivers, true)) {
                $this->writeMessage(
                    $output,
                    "<error>Опцию --location-$driver можно использовать только для выделенной БД.</error>",
                );
                return null;
            }
            if ($location !== "system" && (!is_string($location) || trim($location) === "")) {
                return null;
            }
            if ($location !== "system") {
                $locations[$driver] = trim($location);
            }
        }
        return ["drivers" => $drivers, "locations" => $locations];
    }

    /** @param list<string> $drivers @return array<string,string>|null */
    private function dumpDatabases(array $config, array $drivers, OutputInterface $output): ?array
    {
        $home = getenv("HOME");
        if ($drivers !== [] && (!is_string($home) || $home === "")) {
            return null;
        }
        $snapshots = [];
        try {
            foreach ($drivers as $driver) {
                $path = join_path($home, ".config", "docker-cli", "cache", "project-update", bin2hex(random_bytes(8)));
                $db = (string) $config["data"]["databases"][$driver]["database"];
                $host = (string) $config["data"]["databases"][$driver]["hostname"];
                $code =
                    $driver === "mysql"
                        ? (new MysqlDumpLoader())->dump($db, $path, 4, $output, [], [], $host)
                        : (new PostgresDumpLoader())->dump($db, $path, 4, $output, [], [], $host);
                if ($code !== Command::SUCCESS) {
                    return null;
                }
                $snapshots[$driver] = $path;
            }
        } catch (MissingConfigException) {
            return null;
        }
        return $snapshots;
    }

    /** @param list<string> $drivers @param array<string,string> $snapshots */
    private function restoreDatabases(
        array $config,
        string $name,
        array $drivers,
        array $snapshots,
        OutputInterface $output,
    ): int {
        $compose = new SystemCompose();
        $services = array_map(
            fn (string $driver): string => $compose->databaseService($name, $driver),
            array_values(
                array_filter(
                    $drivers,
                    fn (string $driver): bool => ($config["data"]["databases"][$driver]["hostname"] ?? "") ===
                        "docker-cli-$driver-$name",
                ),
            ),
        );
        if ($services !== []) {
            $code = $this->runProcess(
                array_merge($compose->dockerComposeCommand("up"), ["--detach", ...$services]),
                $compose,
                $output,
            );
            if ($code !== 0) {
                return $code;
            }
        }
        $code = (new DataInitializer())->initialize(
            $name,
            (string) $config["data"]["databases"]["mysql"]["password"],
            (string) $config["data"]["databases"]["postgres"]["password"],
            false,
            $output,
        );
        if ($code !== Command::SUCCESS) {
            return $code;
        }
        foreach ($drivers as $driver) {
            $db = (string) $config["data"]["databases"][$driver]["database"];
            $host = (string) $config["data"]["databases"][$driver]["hostname"];
            $code =
                $driver === "mysql"
                    ? (new MysqlDumpLoader())->load($db, $snapshots[$driver], 4, false, $output, $host)
                    : (new PostgresDumpLoader())->load(
                        $db,
                        (string) $config["data"]["databases"][$driver]["username"],
                        $snapshots[$driver],
                        4,
                        $output,
                        $host,
                    );
            if ($code !== Command::SUCCESS) {
                return $code;
            }
        }
        return Command::SUCCESS;
    }

    /** @param list<string> $drivers */
    private function removeOldInstances(array $config, string $name, array $drivers, OutputInterface $output): void
    {
        foreach ($drivers as $driver) {
            if (($config["data"]["databases"][$driver]["hostname"] ?? "") === "docker-cli-$driver") {
                $database = (string) ($config["data"]["databases"][$driver]["database"] ?? $name);
                $compose = new SystemCompose();
                $script =
                    $driver === "mysql"
                        ? 'database="$1"; MYSQL_PWD="${MYSQL_ROOT_PASSWORD:?}" mysql -uroot -e "DROP ' .
                            'DATABASE IF EXISTS \`$database\`; DROP USER IF EXISTS \`$database\`@\`%\`;"'
                        : 'export PGPASSWORD="${POSTGRES_PASSWORD:?}"; root="${POSTGRES_USER:-system}"; ' .
                            'dropdb -U "$root" --if-exists --force "$1"; dropuser -U "$root" --if-exists "$1"';
                $command = array_merge($compose->dockerComposeCommand("exec"), [
                    "-T",
                    $driver,
                    "sh",
                    "-ec",
                    $script,
                    "sh",
                    $database,
                ]);
                $this->runProcess($command, $compose, $output);
                continue;
            }
            if (($config["data"]["databases"][$driver]["hostname"] ?? "") !== "docker-cli-$driver-$name") {
                continue;
            }
            $location = $config["data"]["databases"][$driver]["location"] ?? null;
            $process = proc_open(
                ["docker", "rm", "--force", "docker-cli-$driver-$name"],
                [STDIN, STDOUT, STDERR],
                $pipes,
            );
            if (is_resource($process)) {
                proc_close($process);
            }
            if (is_string($location) && is_dir($location)) {
                $this->removeDirectory($location);
            }
        }
    }
    private function runProcess(array $command, SystemCompose $compose, OutputInterface $output): int
    {
        $output->writeln("<comment>" . implode(" ", array_map("escapeshellarg", $command)) . "</comment>");
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, $compose->dockerProcessEnvironment());
        return is_resource($process) ? proc_close($process) : Command::FAILURE;
    }

    private function isValidPort(mixed $port): bool
    {
        return is_string($port) && ctype_digit($port) && (int) $port >= 1 && (int) $port <= 65535;
    }

    private function removeDirectory(string $path): void
    {
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== "." && $item !== "..") {
                $child = join_path($path, $item);
                is_dir($child) && !is_link($child) ? $this->removeDirectory($child) : unlink($child);
            }
        }
        rmdir($path);
    }
}
