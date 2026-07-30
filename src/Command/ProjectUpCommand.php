<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Config\SystemCompose;
use DockerCli\Project\ConfigurableServicesRestarter;
use DockerCli\Project\OpenRestyHostRenderer;
use DockerCli\Project\DataInitializer;
use DockerCli\Project\ProjectDatabaseConfig;
use DockerCli\Project\ProjectNameGenerator;
use DockerCli\Project\ProjectRegistry;
use DockerCli\Project\XdebugPortManager;
use DockerCli\Service\TranslatorFactory;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use function DockerCli\Util\join_path;

final class ProjectUpCommand extends AbstractCommand
{
    use DockerComposeRunner;

    public function __construct(private readonly ?CommandContext $context = null)
    {
        parent::__construct('project:up');
        $this->setDescription('Зарегистрировать проект docker-cli.');
        $this->addArgument('project-name', InputArgument::OPTIONAL, 'Имя проекта. По умолчанию используется нормализованное имя директории проекта.');
        $this->addOption('no-restart', null, InputOption::VALUE_NONE, 'Не перезапускать общие проектные сервисы.');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Зарегистрировать проект, даже если фреймворк не удалось определить.');
        $this->addOption('language', null, InputOption::VALUE_REQUIRED, 'Код языка проекта.');
        $this->addOption('framework', null, InputOption::VALUE_REQUIRED, 'Код фреймворка. Если не указан, проект регистрируется без фреймворка.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $frameworkCode = $input->getOption('framework');
        $languageCode = $input->getOption('language');
        if ($languageCode !== null && $languageCode !== 'php' || $frameworkCode !== null && !in_array($frameworkCode, ['symfony', 'laravel', 'bitrix', 'bitrix24'], true)) {
            $output->writeln('<error>Указан неподдерживаемый язык или фреймворк.</error>'); return Command::FAILURE;
        }
        $registry = new ProjectRegistry();
        $projectsDirectory = $registry->projectsDirectory();
        $projectRoot = (string) getcwd();
        $projectName = $this->resolveProjectName($input, $output, $projectRoot, $projectsDirectory);
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

        $documentRoot = $frameworkCode !== null && in_array($frameworkCode, ['symfony', 'laravel'], true) ? join_path($projectRoot, 'public') : $projectRoot;

        if ($frameworkCode === null) {
            $output->writeln('<comment>Фреймворк проекта не определен; проект будет зарегистрирован с базовой веб-конфигурацией.</comment>');
        }

        $projectConfig = (new ProjectDatabaseConfig())->ensure([
            'meta' => [
                'schema' => 'project',
                'version' => 0.1,
            ],
            'data' => [
                'project' => [
                    'name' => $projectName,
                    'enabled' => true,
                    'framework' => $frameworkCode,
                    'language' => $languageCode ?? 'php',
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

        $projectMetadataDirectory = join_path($projectRoot, '.docker-cli');
        if (!is_dir($projectMetadataDirectory) && !mkdir($projectMetadataDirectory, 0775, true) && !is_dir($projectMetadataDirectory)) {
            $output->writeln(sprintf('<error>Не удалось создать директорию "%s".</error>', $projectMetadataDirectory));

            return Command::FAILURE;
        }

        $projectDataDirectory = join_path($projectMetadataDirectory, 'data');
        if (!is_dir($projectDataDirectory) && !mkdir($projectDataDirectory, 0775, true) && !is_dir($projectDataDirectory)) {
            $output->writeln(sprintf('<error>Не удалось создать директорию "%s".</error>', $projectDataDirectory));

            return Command::FAILURE;
        }

        $this->writeYaml(join_path($projectMetadataDirectory, 'project.yaml'), [
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

        $startCode = $this->runOperation(new SystemCompose(), 'up', ['-d'], $output, TranslatorFactory::create());
        if ($startCode !== Command::SUCCESS) {
            return $startCode;
        }

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

        ($this->context ?? CommandContext::fromEnvironment($this))->addMessage(
            new Message(sprintf('Проект **%s** успешно добавлен в контур.', $projectName), notify: true),
        );

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

    private function resolveProjectName(
        InputInterface $input,
        OutputInterface $output,
        string $projectRoot,
        string $projectsDirectory,
    ): string
    {
        $projectName = $input->getArgument('project-name');
        if (is_string($projectName) && $projectName !== '') {
            return $projectName;
        }

        $registeredNames = $this->registeredProjectNames($projectsDirectory);
        $directoryName = $this->normalizeProjectName(basename($projectRoot));
        if ($directoryName !== '' && !in_array($directoryName, $registeredNames, true)) {
            return $directoryName;
        }

        $generatedName = (new ProjectNameGenerator())->generate($registeredNames);
        if ($directoryName === '') {
            $output->writeln(sprintf(
                '<comment>Не удалось сформировать имя проекта из имени директории "%s"; используется сгенерированное имя "%s".</comment>',
                basename($projectRoot),
                $generatedName,
            ));
        } else {
            $output->writeln(sprintf(
                '<comment>Проект "%s" уже зарегистрирован; используется сгенерированное имя "%s".</comment>',
                $directoryName,
                $generatedName,
            ));
        }

        return $generatedName;
    }

    private function normalizeProjectName(string $directoryName): string
    {
        $name = strtolower($directoryName);
        $name = preg_replace('/[^a-z0-9]+/', '-', $name) ?? '';

        return trim($name, '-');
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
