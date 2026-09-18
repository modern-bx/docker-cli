<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Config\SystemCompose;
use DockerCli\Hook\CommandHookRunner;
use DockerCli\Panel\ProjectsSettingsRepository;
use DockerCli\Project\ConfigurableServicesRestarter;
use DockerCli\Project\DataInitializer;
use DockerCli\Project\DedicatedDatabaseComposeRenderer;
use DockerCli\Project\MysqlDumpLoader;
use DockerCli\Project\OpenRestyHostRenderer;
use DockerCli\Project\ProjectDatabaseConfig;
use DockerCli\Project\ProjectNameGenerator;
use DockerCli\Project\ProjectRegistry;

use function DockerCli\Util\join_path;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

final class ProjectCloneCommand extends AbstractCommand
{
    public function __construct(
        private readonly ?ProjectRegistry $registry = null,
        private readonly ?ProjectsSettingsRepository $settings = null,
        private readonly ?MysqlDumpLoader $mysqlDumpLoader = null,
        private readonly ?DataInitializer $dataInitializer = null,
        private readonly ?CommandHookRunner $hookRunner = null,
    ) {
        parent::__construct("project:clone");
        $this->setDescription("Полностью клонировать зарегистрированный проект.");
        $this->addOption(
            "force",
            null,
            InputOption::VALUE_NONE,
            "Очистить существующий целевой проект " . "перед клонированием.",
        );
        $this->addOption("from", null, InputOption::VALUE_REQUIRED, "Кодовое имя исходного проекта.");
        $this->addOption("to", null, InputOption::VALUE_REQUIRED, "Кодовое имя или путь целевого проекта.");
        $this->addOption("location", null, InputOption::VALUE_REQUIRED, "Код расположения проектов.");
        $this->addOption("here", null, InputOption::VALUE_NONE, "Создать проект рядом с исходным.");
        $this->addOption("exclude", null, InputOption::VALUE_REQUIRED, "Список glob-шаблонов через запятую.");
        $this->addOption(
            "mirror",
            null,
            InputOption::VALUE_OPTIONAL,
            "Ускоренное клонирование: tree, db или оба " .
                "значения через запятую. Без значения " .
                "включает оба режима.",
        );
        $this->addOption("skip-db", null, InputOption::VALUE_NONE, "Не клонировать базы данных.");
        $this->addOption("dbms", null, InputOption::VALUE_REQUIRED, "Список СУБД для клонирования через запятую.");
        $this->addOption(
            "dedicated-db",
            null,
            InputOption::VALUE_REQUIRED,
            "Выделенные СУБД целевого проекта: mysql, postgres " .
                "или false для системных инстансов. По " .
                "умолчанию наследуются настройки исходного " .
                "проекта.",
        );
        $this->addOption(
            "location-mysql",
            null,
            InputOption::VALUE_REQUIRED,
            "Каталог данных выделенного MySQL целевого проекта.",
        );
        $this->addOption(
            "location-postgres",
            null,
            InputOption::VALUE_REQUIRED,
            "Каталог данных выделенного PostgreSQL целевого проекта.",
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mirror = $this->resolveMirror($input, $output);
        if ($mirror === null) {
            return Command::INVALID;
        }
        if ($input->getOption("here") && $input->getOption("location") !== null) {
            $this->writeMessage(
                $output,
                "<error>Опции --here и --location нельзя использовать " . "одновременно.</error>",
            );
            return Command::FAILURE;
        }
        if ($input->getOption("skip-db") && $input->getOption("dbms") !== null) {
            $this->writeMessage(
                $output,
                "<error>Опции --skip-db и --dbms нельзя использовать " . "одновременно.</error>",
            );
            return Command::FAILURE;
        }
        $dbms = $this->resolveDbms($input->getOption("dbms"), (bool) $input->getOption("skip-db"), $output);
        if ($dbms === null) {
            return Command::FAILURE;
        }
        $registry = $this->registry ?? new ProjectRegistry();
        $from = $input->getOption("from");
        $from = is_string($from) && $from !== "" ? $from : $registry->projectNameFromContext();
        if ($from === null || !$registry->hasProject($from)) {
            $this->writeMessage(
                $output,
                "<error>Исходный проект не найден. Укажите его " . "код через --from.</error>",
            );
            return Command::FAILURE;
        }
        $sourceConfig = $registry->readProjectConfig($from);
        $dedicated = $this->resolveDedicatedDatabases($input, $sourceConfig, $from, $output);
        if ($dedicated === null) {
            return Command::INVALID;
        }
        $sourceRoot = $sourceConfig["data"]["project"]["root"] ?? null;
        if (!is_string($sourceRoot) || !is_dir($sourceRoot)) {
            $this->writeMessage($output, "<error>Директория исходного проекта не найдена.</error>");
            return Command::FAILURE;
        }

        $to = $input->getOption("to");
        $isPath = is_string($to) && (str_contains($to, "/") || str_contains($to, "\\"));
        $names = $registry->registeredProjectNames();
        $name = is_string($to) && $to !== "" && !$isPath ? $to : null;
        if ($name === null && $isPath) {
            $name = $this->normalizeName(basename((string) $to));
        }
        if ($name === null || $name === "" || (in_array($name, $names, true) && $isPath)) {
            $name = (new ProjectNameGenerator())->generate($names);
        }
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name) !== 1) {
            $this->writeMessage($output, "<error>Некорректное кодовое имя целевого проекта.</error>");
            return Command::FAILURE;
        }

        try {
            $destination = $isPath
                ? $this->absolutePath((string) $to)
                : $this->destinationForName($name, $sourceRoot, $input);
        } catch (\InvalidArgumentException $exception) {
            $this->writeMessage($output, "<error>" . $exception->getMessage() . "</error>");
            return Command::FAILURE;
        }
        $existingName = $this->projectAtPath($destination, $registry);
        if (
            ($existingName !== null || $registry->hasProject($name) || $this->directoryHasFiles($destination)) &&
            !$input->getOption("force")
        ) {
            $this->writeMessage(
                $output,
                "<error>Целевой проект или директория уже " .
                    "существует. Используйте --force для " .
                    "очистки.</error>",
            );
            return Command::FAILURE;
        }
        if ($input->getOption("force") && is_dir($destination)) {
            $this->wipe($destination);
        }
        if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
            $this->writeMessage($output, "<error>Не удалось создать целевую директорию.</error>");
            return Command::FAILURE;
        }

        $hookArguments = $input instanceof ArgvInput ? $input->getRawTokens(true) : [];
        $beforeHookCode = ($this->hookRunner ?? new CommandHookRunner())->run(
            "project:clone",
            "before",
            $hookArguments,
        );
        if ($beforeHookCode !== Command::SUCCESS) {
            return $beforeHookCode;
        }

        $startedAt = new \DateTimeImmutable();
        $started = microtime(true);
        $this->writeMessage(
            $output,
            sprintf(
                '<info>Клонирование проекта "%s" в "%s" началось (%s).</info>',
                $from,
                $name,
                $startedAt->format("H:i:s"),
            ),
        );

        $config = $sourceConfig;
        $documentRoot = $config["data"]["project"]["document_root"] ?? $sourceRoot;
        $relativeDocumentRoot =
            is_string($documentRoot) && str_starts_with($documentRoot, rtrim($sourceRoot, "/") . "/")
                ? substr($documentRoot, strlen(rtrim($sourceRoot, "/")) + 1)
                : "";
        $config["data"]["project"]["name"] = $name;
        $config["data"]["project"]["root"] = $destination;
        $config["data"]["project"]["document_root"] =
            $relativeDocumentRoot === "" ? $destination : join_path($destination, $relativeDocumentRoot);
        foreach (["mysql", "postgres"] as $driver) {
            unset($config["data"]["databases"][$driver]["hostname"], $config["data"]["databases"][$driver]["location"]);
        }
        if (count($dedicated["locations"]) + count($dedicated["systemLocations"]) < count($dedicated["drivers"])) {
            $defaultLocation = current(
                array_filter(
                    ($this->settings ?? new ProjectsSettingsRepository())->databaseLocations(),
                    static fn (array $location): bool => $location["default"],
                ),
            );
            if (is_array($defaultLocation)) {
                foreach ($dedicated["drivers"] as $driver) {
                    if (!in_array($driver, $dedicated["systemLocations"], true)) {
                        $dedicated["locations"][$driver] ??= join_path($defaultLocation["path"], $driver . "-" . $name);
                    }
                }
            }
        }
        $mirroredDatabases = $this->mirroredDatabaseDrivers(
            $mirror,
            $dbms,
            $sourceConfig,
            $from,
            $dedicated["drivers"],
            $input,
        );
        $config = (new ProjectDatabaseConfig())->ensure($config, $dedicated["drivers"], $dedicated["locations"]);
        if (!is_dir($registry->projectDirectory($name))) {
            mkdir($registry->projectDirectory($name), 0775, true);
        }
        $registry->writeProjectConfig($name, $config);
        (new DedicatedDatabaseComposeRenderer())->render();
        $mirroredDatabases = array_values(
            array_filter(
                $mirroredDatabases,
                fn (string $driver): bool => $this->canMirrorDatabase($from, $name, $driver, $sourceConfig, $config),
            ),
        );
        foreach ($mirroredDatabases as $driver) {
            $configuredDatabase = $sourceConfig["data"]["databases"][$driver]["database"] ?? null;
            $sourceDatabase =
                is_string($configuredDatabase) && $configuredDatabase !== "" ? $configuredDatabase : $from;
            $configuredUsername = $sourceConfig["data"]["databases"][$driver]["username"] ?? null;
            $sourceUsername =
                is_string($configuredUsername) && $configuredUsername !== "" ? $configuredUsername : $sourceDatabase;
            $config["data"]["databases"][$driver]["database"] = $sourceDatabase;
            $config["data"]["databases"][$driver]["username"] = $sourceUsername;
        }
        if ($mirroredDatabases !== []) {
            $registry->writeProjectConfig($name, $config);
        }
        $metadata = join_path($destination, ".docker-cli");

        $excludes = [".docker-cli"];
        $rawExclude = $input->getOption("exclude");
        if (is_string($rawExclude)) {
            foreach (explode(",", $rawExclude) as $pattern) {
                if (trim($pattern) !== "") {
                    $excludes[] = ltrim(trim($pattern), "./");
                }
            }
        }
        $filesStarted = microtime(true);
        if ($rawExclude === null && in_array("tree", $mirror, true) && $this->canReflink($sourceRoot, $destination)) {
            $command = sprintf(
                "cp -a --reflink=always -- %s/. %s/",
                escapeshellarg($sourceRoot),
                escapeshellarg($destination),
            );
            passthru($command, $status);
            if ($status === 0) {
                $this->wipeMetadata($destination);
            }
        } else {
            $excludeArgs = implode(
                " ",
                array_map(static fn (string $pattern): string => "--exclude=" . escapeshellarg($pattern), $excludes),
            );
            $command = sprintf("tar %s -cf - . | (cd %s && tar -xf -)", $excludeArgs, escapeshellarg($destination));
            passthru("cd " . escapeshellarg($sourceRoot) . " && " . $command, $status);
        }
        $filesDuration = microtime(true) - $filesStarted;
        if ($status !== 0) {
            $this->writeMessage($output, "<error>Копирование проекта завершилось с ошибкой.</error>");
            return Command::FAILURE;
        }
        if (!is_dir($metadata)) {
            mkdir($metadata, 0775, true);
        }
        file_put_contents(
            join_path($metadata, "project.yaml"),
            Yaml::dump(
                ["meta" => ["schema" => "project-meta", "version" => 0.1], "data" => ["project" => ["name" => $name]]],
                4,
                2,
            ),
        );
        $databaseDuration = 0.0;
        if ($dbms !== []) {
            $databaseStarted = microtime(true);
            $databaseCode = $this->mirrorDedicatedDatabases(
                $from,
                $name,
                $mirroredDatabases,
                $sourceConfig,
                $config,
                $output,
            );
            $regularDedicated = array_values(array_diff($dedicated["drivers"], $mirroredDatabases));
            if ($databaseCode === Command::SUCCESS) {
                $databaseCode = $this->startDedicatedDatabases($name, $regularDedicated, $output);
            }
            $regularDrivers = array_values(array_diff(["mysql", "postgres"], $mirroredDatabases));
            if ($databaseCode === Command::SUCCESS) {
                $databaseCode = $this->initializeTargetDatabases($config, $name, $regularDrivers, $output);
            }
            if (
                $databaseCode === Command::SUCCESS &&
                in_array("mysql", $dbms, true) &&
                !in_array("mysql", $mirroredDatabases, true)
            ) {
                $databaseCode = $this->cloneMysqlDatabase($sourceConfig, $config, $output);
            }
            if (
                $databaseCode === Command::SUCCESS &&
                in_array("postgres", $dbms, true) &&
                !in_array("postgres", $mirroredDatabases, true)
            ) {
                $databaseCode = $this->clonePostgresDatabase($sourceConfig, $config, $output);
            }
            $databaseDuration = microtime(true) - $databaseStarted;
            if ($databaseCode !== Command::SUCCESS) {
                return $databaseCode;
            }
        }
        try {
            (new OpenRestyHostRenderer())->render();
        } catch (\RuntimeException $exception) {
            $this->writeMessage(
                $output,
                "<error>Не удалось пересобрать конфигурацию " .
                    "хостов OpenResty: " .
                    $exception->getMessage() .
                    "</error>",
            );
            return Command::FAILURE;
        }
        $restartCode = (new ConfigurableServicesRestarter())->restart($output);
        if ($restartCode !== Command::SUCCESS) {
            return $restartCode;
        }

        $this->writeMessage(
            $output,
            sprintf(
                '<info>Проект "%s" клонирован в "%s" (%s; всего: %s; ' . "копирование файлов: %s%s).</info>",
                $name,
                $destination,
                (new \DateTimeImmutable())->format("H:i:s"),
                $this->formatDuration(microtime(true) - $started),
                $this->formatDuration($filesDuration),
                $databaseDuration > 0 ? "; копирование БД: " . $this->formatDuration($databaseDuration) : "",
            ),
        );
        return ($this->hookRunner ?? new CommandHookRunner())->run("project:clone", "after", $hookArguments);
    }

    private function destinationForName(string $name, string $sourceRoot, InputInterface $input): string
    {
        if ($input->getOption("here")) {
            return join_path(dirname($sourceRoot), $name);
        }
        $locations = ($this->settings ?? new ProjectsSettingsRepository())->locations();
        $code = $input->getOption("location");
        foreach ($locations as $location) {
            if (($code !== null && $location["code"] === $code) || ($code === null && $location["default"])) {
                return join_path($location["path"], $name);
            }
        }
        throw new \InvalidArgumentException(
            $code === null
                ? "Не настроено расположение проектов по умолчанию."
                : sprintf('Расположение "%s" не найдено.', $code),
        );
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, "/") ? rtrim($path, "/") : join_path((string) getcwd(), $path);
    }
    private function normalizeName(string $name): string
    {
        return trim(preg_replace("/[^a-z0-9]+/", "-", strtolower($name)) ?? "", "-");
    }
    private function formatDuration(float $seconds): string
    {
        $remaining = max(0, (int) round($seconds));
        $parts = [];
        foreach (
            [
                [86400, "день", "дня", "дней"],
                [3600, "час", "часа", "часов"],
                [60, "минута", "минуты", "минут"],
            ] as [$size, $one, $few, $many]
        ) {
            $value = intdiv($remaining, $size);
            if ($value > 0) {
                $parts[] = $value . " " . $this->plural($value, $one, $few, $many);
                $remaining %= $size;
            }
        }
        if ($remaining > 0 || $parts === []) {
            $parts[] = $remaining . " " . $this->plural($remaining, "секунда", "секунды", "секунд");
        }
        return implode(" ", $parts);
    }

    private function plural(int $value, string $one, string $few, string $many): string
    {
        $mod100 = $value % 100;
        if ($mod100 >= 11 && $mod100 <= 14) {
            return $many;
        }
        return match ($value % 10) {
            1 => $one,
            2, 3, 4 => $few,
            default => $many,
        };
    }

    /** @return list<string>|null */
    private function resolveMirror(InputInterface $input, OutputInterface $output): ?array
    {
        if (!$input->hasParameterOption("--mirror")) {
            return [];
        }
        $option = $input->getOption("mirror");
        $modes =
            is_string($option) && trim($option) !== ""
                ? array_values(array_unique(array_filter(array_map("trim", explode(",", $option)))))
                : ["tree", "db"];
        if (array_diff($modes, ["tree", "db"]) !== []) {
            $this->writeMessage($output, "<error>Опция --mirror должна содержать tree и/или db.</error>");
            return null;
        }
        return $modes;
    }

    private function canReflink(string $source, string $destination): bool
    {
        $sourceStat = stat($source);
        $destinationStat = stat($destination);
        if ($sourceStat === false || $destinationStat === false || $sourceStat["dev"] !== $destinationStat["dev"]) {
            return false;
        }
        $type = shell_exec("stat -f -c %T -- " . escapeshellarg($source) . " 2>/dev/null");
        return trim((string) $type) === "btrfs";
    }

    /**
     * @param list<string> $mirror @param list<string> $dbms @param array<string, mixed> $sourceConfig @param
     * list<string> $targetDedicated @return list<string>
     */
    private function mirroredDatabaseDrivers(
        array $mirror,
        array $dbms,
        array $sourceConfig,
        string $sourceName,
        array $targetDedicated,
        InputInterface $input,
    ): array {
        if (!in_array("db", $mirror, true) || !is_string($input->getOption("dedicated-db"))) {
            return [];
        }

        return array_values(
            array_filter(
                ["mysql", "postgres"],
                static fn (string $driver): bool => in_array($driver, $dbms, true) &&
                    in_array($driver, $targetDedicated, true) &&
                    ($sourceConfig["data"]["databases"][$driver]["hostname"] ?? null) ===
                        sprintf("docker-cli-%s-%s", $driver, $sourceName),
            ),
        );
    }

    /** @param array<string, mixed> $sourceConfig @param array<string, mixed> $targetConfig */
    private function canMirrorDatabase(
        string $sourceName,
        string $targetName,
        string $driver,
        array $sourceConfig,
        array $targetConfig,
    ): bool {
        $compose = new SystemCompose();
        $sourceLocation = $sourceConfig["data"]["databases"][$driver]["location"] ?? null;
        $targetLocation = $targetConfig["data"]["databases"][$driver]["location"] ?? null;
        $sourceData = join_path(
            $compose->dedicatedDatabaseDirectory(
                $sourceName,
                $driver,
                is_string($sourceLocation) ? $sourceLocation : null,
            ),
            "data",
        );
        $targetData = join_path(
            $compose->dedicatedDatabaseDirectory(
                $targetName,
                $driver,
                is_string($targetLocation) ? $targetLocation : null,
            ),
            "data",
        );

        return realpath($sourceData) !== realpath($targetData) && $this->canReflink($sourceData, $targetData);
    }

    private function wipeMetadata(string $destination): void
    {
        $metadata = join_path($destination, ".docker-cli");
        if (is_dir($metadata) || is_link($metadata)) {
            $this->remove($metadata);
        }
    }

    /** @return list<string>|null */
    private function resolveDbms(mixed $option, bool $skip, OutputInterface $output): ?array
    {
        if ($skip) {
            return [];
        }
        $explicit = is_string($option);
        $dbms = $explicit
            ? array_values(array_unique(array_filter(array_map("trim", explode(",", $option)))))
            : ["mysql", "postgres"];
        if ($dbms === [] || array_diff($dbms, ["mysql", "postgres"]) !== []) {
            $this->writeMessage($output, "<error>Опция --dbms должна содержать mysql и/или postgres.</error>");
            return null;
        }
        return $dbms;
    }

    /**
     * @param array<string, mixed> $sourceConfig @return array{drivers: list<string>, locations: array<string, string>,
     * systemLocations: list<string>}|null
     */
    private function resolveDedicatedDatabases(
        InputInterface $input,
        array $sourceConfig,
        string $sourceName,
        OutputInterface $output,
    ): ?array {
        $option = $input->getOption("dedicated-db");
        $drivers = is_string($option)
            ? ($option === "false"
                ? []
                : array_values(array_unique(array_filter(array_map("trim", explode(",", $option))))))
            : array_values(
                array_filter(
                    ["mysql", "postgres"],
                    static fn (string $driver): bool => ($sourceConfig["data"]["databases"][$driver]["hostname"] ??
                        null) ===
                        sprintf("docker-cli-%s-%s", $driver, $sourceName),
                ),
            );
        if (array_diff($drivers, ["mysql", "postgres"]) !== []) {
            $this->writeMessage($output, "<error>Опция --dedicated-db поддерживает mysql, postgres или false.</error>");
            return null;
        }
        $locations = [];
        $systemLocations = [];
        foreach (["mysql", "postgres"] as $driver) {
            $location = $input->getOption("location-" . $driver);
            if ($location === null) {
                continue;
            }
            if (!in_array($driver, $drivers, true)) {
                $this->writeMessage(
                    $output,
                    sprintf(
                        "<error>Опцию --location-%s можно использовать " . "только для выделенной БД.</error>",
                        $driver,
                    ),
                );
                return null;
            }
            if ($location === "system") {
                $systemLocations[] = $driver;
                continue;
            }
            if (
                !is_string($location) ||
                trim($location) === "" ||
                in_array(trim($location), [".", "..", DIRECTORY_SEPARATOR], true)
            ) {
                $this->writeMessage(
                    $output,
                    sprintf("<error>Опция --location-%s должна содержать путь.</error>", $driver),
                );
                return null;
            }
            $locations[$driver] = trim($location);
        }
        return ["drivers" => $drivers, "locations" => $locations, "systemLocations" => $systemLocations];
    }

    /** @param list<string> $drivers */
    private function startDedicatedDatabases(string $projectName, array $drivers, OutputInterface $output): int
    {
        if ($drivers === []) {
            return Command::SUCCESS;
        }
        $compose = new SystemCompose();
        try {
            $compose->assertInitialized();
        } catch (MissingConfigException $exception) {
            $this->writeMessage(
                $output,
                sprintf(
                    "<error>Системная конфигурация не " . "инициализирована. Отсутствуют файлы: %s.</error>",
                    implode(", ", $exception->missingFiles()),
                ),
            );
            return Command::FAILURE;
        }
        $services = array_map(
            static fn (string $driver): string => $compose->databaseService($projectName, $driver),
            $drivers,
        );
        $command = array_merge($compose->dockerComposeCommand("up"), ["--detach"], $services);
        $this->writeMessage(
            $output,
            "<comment>Выполняется: " . implode(" ", array_map("escapeshellarg", $command)) . "</comment>",
        );
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, $compose->dockerProcessEnvironment());
        return is_resource($process) ? proc_close($process) : Command::FAILURE;
    }

    /** @param array<string, mixed> $targetConfig @param list<string> $drivers */
    private function initializeTargetDatabases(
        array $targetConfig,
        string $targetName,
        array $drivers,
        OutputInterface $output,
    ): int {
        $mysqlPassword = $targetConfig["data"]["databases"]["mysql"]["password"] ?? null;
        $postgresPassword = $targetConfig["data"]["databases"]["postgres"]["password"] ?? null;
        if (!is_string($mysqlPassword) || !is_string($postgresPassword)) {
            $this->writeMessage($output, "<error>В конфигурации проекта отсутствуют " . "пароли баз данных.</error>");
            return Command::FAILURE;
        }
        try {
            return ($this->dataInitializer ?? new DataInitializer())->initialize(
                $targetName,
                $mysqlPassword,
                $postgresPassword,
                false,
                $output,
                $drivers,
            );
        } catch (MissingConfigException $exception) {
            $this->writeMessage(
                $output,
                sprintf(
                    "<error>Системная конфигурация не " . "инициализирована. Отсутствуют файлы: %s.</error>",
                    implode(", ", $exception->missingFiles()),
                ),
            );
            return Command::FAILURE;
        }
    }

    /**
     * @param list<string> $drivers @param array<string, mixed> $sourceConfig @param array<string, mixed> $targetConfig
     */
    private function mirrorDedicatedDatabases(
        string $sourceName,
        string $targetName,
        array $drivers,
        array $sourceConfig,
        array $targetConfig,
        OutputInterface $output,
    ): int {
        if ($drivers === []) {
            return Command::SUCCESS;
        }
        $compose = new SystemCompose();
        try {
            $compose->assertInitialized();
        } catch (MissingConfigException $exception) {
            $this->writeMessage(
                $output,
                sprintf(
                    "<error>Системная конфигурация не " . "инициализирована. Отсутствуют файлы: %s.</error>",
                    implode(", ", $exception->missingFiles()),
                ),
            );
            return Command::FAILURE;
        }
        foreach ($drivers as $driver) {
            $sourceService = $compose->databaseService($sourceName, $driver);
            $targetService = $compose->databaseService($targetName, $driver);
            $stopCode = $this->runComposeProcess($compose, ["stop", $sourceService], $output);
            if ($stopCode !== Command::SUCCESS) {
                return $stopCode;
            }

            $copied = false;
            try {
                $sourceLocation = $sourceConfig["data"]["databases"][$driver]["location"] ?? null;
                $targetLocation = $targetConfig["data"]["databases"][$driver]["location"] ?? null;
                $sourceData = join_path(
                    $compose->dedicatedDatabaseDirectory(
                        $sourceName,
                        $driver,
                        is_string($sourceLocation) ? $sourceLocation : null,
                    ),
                    "data",
                );
                $targetData = join_path(
                    $compose->dedicatedDatabaseDirectory(
                        $targetName,
                        $driver,
                        is_string($targetLocation) ? $targetLocation : null,
                    ),
                    "data",
                );
                $command = [
                    "sudo",
                    "cp",
                    "-a",
                    "--reflink=always",
                    "--",
                    rtrim($sourceData, "/") . "/.",
                    rtrim($targetData, "/") . "/",
                ];
                $this->writeMessage(
                    $output,
                    "<comment>Выполняется: " . implode(" ", array_map("escapeshellarg", $command)) . "</comment>",
                );
                $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);
                $copyCode = is_resource($process) ? proc_close($process) : Command::FAILURE;
                $copied = $copyCode === Command::SUCCESS;
            } finally {
                $services = $copied ? [$sourceService, $targetService] : [$sourceService];
                $startCode = $this->runComposeProcess($compose, ["up", "--detach", ...$services], $output);
            }
            if (!$copied) {
                return Command::FAILURE;
            }
            if ($startCode !== Command::SUCCESS) {
                return $startCode;
            }
        }

        return Command::SUCCESS;
    }

    /** @param list<string> $arguments */
    private function runComposeProcess(SystemCompose $compose, array $arguments, OutputInterface $output): int
    {
        $command = array_merge($compose->dockerComposeCommand(array_shift($arguments) ?? ""), $arguments);
        $this->writeMessage(
            $output,
            "<comment>Выполняется: " . implode(" ", array_map("escapeshellarg", $command)) . "</comment>",
        );
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, $compose->dockerProcessEnvironment());

        return is_resource($process) ? proc_close($process) : Command::FAILURE;
    }

    /** @param array<string, mixed> $sourceConfig @param array<string, mixed> $targetConfig */
    private function cloneMysqlDatabase(array $sourceConfig, array $targetConfig, OutputInterface $output): int
    {
        $source = $sourceConfig["data"]["databases"]["mysql"]["database"] ?? null;
        $target = $targetConfig["data"]["databases"]["mysql"]["database"] ?? null;
        if (!is_string($source) || $source === "" || !is_string($target) || $target === "") {
            $this->writeMessage(
                $output,
                "<error>В конфигурации проекта отсутствуют " . "параметры баз данных.</error>",
            );
            return Command::FAILURE;
        }
        $home = getenv("HOME");
        if (!is_string($home) || $home === "") {
            $this->writeMessage(
                $output,
                "<error>Не удалось определить домашнюю " . "директорию для временного снимка БД.</error>",
            );
            return Command::FAILURE;
        }
        $snapshot = join_path($home, ".config", "docker-cli", "cache", "project-clone", bin2hex(random_bytes(8)));
        $loader = $this->mysqlDumpLoader ?? new MysqlDumpLoader();
        try {
            $sourceHost = $sourceConfig["data"]["databases"]["mysql"]["hostname"] ?? "docker-cli-mysql";
            $targetHost = $targetConfig["data"]["databases"]["mysql"]["hostname"] ?? "docker-cli-mysql";
            $code = $loader->dump(
                $source,
                $snapshot,
                4,
                $output,
                [],
                [],
                is_string($sourceHost) ? $sourceHost : "docker-cli-mysql",
            );
            if ($code !== Command::SUCCESS) {
                return $code;
            }
            return $loader->load(
                $target,
                $snapshot,
                4,
                false,
                $output,
                is_string($targetHost) ? $targetHost : "docker-cli-mysql",
            );
        } catch (MissingConfigException $exception) {
            $this->writeMessage(
                $output,
                sprintf(
                    "<error>Системная конфигурация не " . "инициализирована. Отсутствуют файлы: %s.</error>",
                    implode(", ", $exception->missingFiles()),
                ),
            );
            return Command::FAILURE;
        } finally {
            if (is_dir($snapshot)) {
                $this->remove($snapshot);
            }
        }
    }

    /** @param array<string, mixed> $sourceConfig @param array<string, mixed> $targetConfig */
    private function clonePostgresDatabase(array $sourceConfig, array $targetConfig, OutputInterface $output): int
    {
        $source = $sourceConfig["data"]["databases"]["postgres"]["database"] ?? null;
        $target = $targetConfig["data"]["databases"]["postgres"]["database"] ?? null;
        $sourceUser = $sourceConfig["data"]["databases"]["postgres"]["username"] ?? $source;
        $targetUser = $targetConfig["data"]["databases"]["postgres"]["username"] ?? $target;
        if (
            !is_string($source) ||
            $source === "" ||
            !is_string($target) ||
            $target === "" ||
            !is_string($sourceUser) ||
            $sourceUser === "" ||
            !is_string($targetUser) ||
            $targetUser === ""
        ) {
            $this->writeMessage(
                $output,
                "<error>В конфигурации проекта отсутствуют " . "параметры PostgreSQL.</error>",
            );
            return Command::FAILURE;
        }
        try {
            $sourceHost = $sourceConfig["data"]["databases"]["postgres"]["hostname"] ?? "docker-cli-postgres";
            $targetHost = $targetConfig["data"]["databases"]["postgres"]["hostname"] ?? "docker-cli-postgres";
            return ($this->dataInitializer ?? new DataInitializer())->clonePostgres(
                $source,
                $target,
                $sourceUser,
                $targetUser,
                $output,
                is_string($sourceHost) ? $sourceHost : "docker-cli-postgres",
                is_string($targetHost) ? $targetHost : "docker-cli-postgres",
            );
        } catch (MissingConfigException $exception) {
            $this->writeMessage(
                $output,
                sprintf(
                    "<error>Системная конфигурация не " . "инициализирована. Отсутствуют файлы: %s.</error>",
                    implode(", ", $exception->missingFiles()),
                ),
            );
            return Command::FAILURE;
        }
    }
    private function directoryHasFiles(string $path): bool
    {
        return is_dir($path) && count(scandir($path) ?: []) > 2;
    }
    private function projectAtPath(string $path, ProjectRegistry $registry): ?string
    {
        foreach ($registry->registeredProjectNames() as $name) {
            if (($registry->readProjectConfig($name)["data"]["project"]["root"] ?? null) === $path) {
                return $name;
            }
        }
        return null;
    }
    private function wipe(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === "." || $entry === ".." || $entry === ".docker-cli") {
                continue;
            }
            $this->remove(join_path($path, $entry));
        }
    }
    private function remove(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== "." && $entry !== "..") {
                $this->remove(join_path($path, $entry));
            }
        }
        rmdir($path);
    }
}
