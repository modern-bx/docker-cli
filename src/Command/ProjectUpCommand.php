<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Framework\Description\FrameworkDescriptionService;
use DockerCli\Config\MissingConfigException;
use DockerCli\Framework\FrameworkDetectionService;
use DockerCli\Project\ConfigurableServicesRestarter;
use DockerCli\Project\OpenRestyHostRenderer;
use DockerCli\Project\DataInitializer;
use DockerCli\Project\ProjectDatabaseConfig;
use DockerCli\Project\ProjectNameGenerator;
use DockerCli\Project\ProjectRegistry;
use DockerCli\Project\XdebugPortManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use function DockerCli\Util\join_path;

final class ProjectUpCommand extends Command
{
    public function __construct(
        private readonly ?FrameworkDetectionService $detectionService = null,
        private readonly ?FrameworkDescriptionService $descriptionService = null,
    ) {
        parent::__construct('project:up');
        $this->setDescription('Зарегистрировать проект docker-cli.');
        $this->addArgument('project-name', InputArgument::OPTIONAL, 'Имя проекта. По умолчанию генерируется случайный идентификатор adjective-animal.');
        $this->addOption('no-restart', null, InputOption::VALUE_NONE, 'Не перезапускать общие проектные сервисы.');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Зарегистрировать проект, даже если фреймворк не удалось определить.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $framework = ($this->detectionService ?? FrameworkDetectionService::createDefault())->detect();
        $force = (bool) $input->getOption('force');
        if ($framework === null && !$force) {
            $output->writeln('<error>Не удалось определить фреймворк проекта. Используйте --force, чтобы зарегистрировать проект без определения фреймворка.</error>');

            return Command::FAILURE;
        }

        $registry = new ProjectRegistry();
        $projectsDirectory = $registry->projectsDirectory();
        $projectName = $this->resolveProjectName($input, $projectsDirectory);
        if (!$this->isValidProjectName($projectName)) {
            $output->writeln(sprintf('<error>Имя проекта "%s" не соответствует конвенции: используйте строчные латинские буквы, цифры и дефисы; имя должно начинаться и заканчиваться буквой или цифрой.</error>', $projectName));

            return Command::FAILURE;
        }

        $projectDirectory = join_path($projectsDirectory, $projectName);

        if (is_dir($projectDirectory)) {
            $output->writeln(sprintf('<error>Проект "%s" уже зарегистрирован.</error>', $projectName));

            return Command::FAILURE;
        }

        if (!is_dir($projectsDirectory) && !mkdir($projectsDirectory, 0775, true) && !is_dir($projectsDirectory)) {
            $output->writeln(sprintf('<error>Не удалось создать директорию "%s".</error>', $projectsDirectory));

            return Command::FAILURE;
        }

        if (!mkdir($projectDirectory, 0775) && !is_dir($projectDirectory)) {
            $output->writeln(sprintf('<error>Не удалось создать директорию проекта "%s".</error>', $projectDirectory));

            return Command::FAILURE;
        }

        $description = $framework === null ? null : ($this->descriptionService ?? new FrameworkDescriptionService())->describe($framework);
        $projectRoot = $framework === null ? (string) getcwd() : $framework->getProjectRoot();
        $documentRoot = $framework === null ? $projectRoot : $framework->getDocumentRoot();

        if ($framework === null) {
            $output->writeln('<comment>Фреймворк проекта не определен; проект будет зарегистрирован без веб-конфигурации фреймворка.</comment>');
        }

        $projectConfig = (new ProjectDatabaseConfig())->ensure([
            'meta' => [
                'schema' => 'project',
                'version' => 0.1,
            ],
            'data' => [
                'project' => [
                    'name' => $projectName,
                    'framework' => $description?->getCodeName()->value ?? false,
                    'language' => 'php',
                    ...$this->languageVersionConfig($projectRoot),
                    'root' => $projectRoot,
                    'document_root' => $documentRoot,
                    'xdebug' => [
                        'client_port' => (new XdebugPortManager())->nextPort($projectsDirectory),
                    ],
                ],
            ],
        ]);
        $this->writeYaml(join_path($projectDirectory, 'project.yaml'), $projectConfig);

        $this->writeYaml(join_path($projectRoot, '.docker-cli.yaml'), [
            'meta' => [
                'schema' => 'project-meta',
                'version' => 0.1,
            ],
            'data' => [
                'project' => [
                    'name' => $projectName,
                ],
            ],
        ]);

        $mysqlPassword = $projectConfig['data']['databases']['mysql']['password'];
        $postgresPassword = $projectConfig['data']['databases']['postgres']['password'];
        try {
            $dataInitCode = (new DataInitializer())->initialize($projectName, $mysqlPassword, $postgresPassword, false, $output);
        } catch (MissingConfigException $exception) {
            $output->writeln(sprintf('<error>Системная конфигурация не инициализирована. Отсутствуют файлы: %s.</error>', implode(', ', $exception->missingFiles())));

            return Command::FAILURE;
        }
        if ($dataInitCode !== Command::SUCCESS) {
            return $dataInitCode;
        }

        (new OpenRestyHostRenderer())->render();

        if (!$input->getOption('no-restart')) {
            $restartCode = (new ConfigurableServicesRestarter())->restart($output);
            if ($restartCode !== Command::SUCCESS) {
                return $restartCode;
            }
        }

        $output->writeln(sprintf('<info>Проект "%s" зарегистрирован.</info>', $projectName));

        return Command::SUCCESS;
    }

    /** @return array{version?: string} */
    private function languageVersionConfig(string $projectRoot): array
    {
        $version = $this->detectPhpVersion($projectRoot);

        return $version === null ? [] : ['version' => $version];
    }

    private function detectPhpVersion(string $projectRoot): ?string
    {
        $composerJson = join_path($projectRoot, 'composer.json');
        if (!is_file($composerJson)) {
            return null;
        }

        $contents = file_get_contents($composerJson);
        if ($contents === false) {
            return null;
        }

        $composer = json_decode($contents, true);
        if (!is_array($composer)) {
            return null;
        }

        $constraint = $composer['require']['php'] ?? null;
        if (!is_string($constraint)) {
            return null;
        }

        if (preg_match('/(?<!\d)([78]\.\d+)(?!\d)/', $constraint, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function resolveProjectName(InputInterface $input, string $projectsDirectory): string
    {
        $projectName = $input->getArgument('project-name');
        if (is_string($projectName) && $projectName !== '') {
            return $projectName;
        }

        return (new ProjectNameGenerator())->generate($this->registeredProjectNames($projectsDirectory));
    }

    /** @return list<string> */
    private function registeredProjectNames(string $projectsDirectory): array
    {
        if (!is_dir($projectsDirectory)) {
            return [];
        }

        $names = [];
        foreach (glob(join_path($projectsDirectory, '*'), GLOB_ONLYDIR) ?: [] as $directory) {
            $names[] = basename($directory);
        }

        return $names;
    }

    private function isValidProjectName(string $projectName): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $projectName);
    }

    /** @param array<string, mixed> $data */
    private function writeYaml(string $file, array $data): void
    {
        file_put_contents($file, Yaml::dump($data, 4, 2));
    }
}
