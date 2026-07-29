<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Framework\FrameworkDetectionService;
use DockerCli\Project\ConfigurableServicesRestarter;
use DockerCli\Project\DataInitializer;
use DockerCli\Project\OpenRestyHostRenderer;
use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use function DockerCli\Util\join_path;

final class ProjectDownCommand extends Command
{
    public function __construct(
        private readonly ?FrameworkDetectionService $detectionService = null,
        private readonly ?DataInitializer $dataInitializer = null,
    ) {
        parent::__construct('project:down');
        $this->setDescription('Удалить регистрацию проекта docker-cli.');
        $this->addOption('no-restart', null, InputOption::VALUE_NONE, 'Не перезапускать общие проектные сервисы.');
        $this->addOption('drop', null, InputOption::VALUE_NONE, 'Удалить базы данных и пользователей проекта во всех СУБД.');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Подтвердить удаление баз данных и пользователей с --drop.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('drop') && !$input->getOption('force')) {
            $output->writeln('<error>Опция --drop удаляет базы данных и пользователей проекта. Повторите команду с --force.</error>');

            return Command::FAILURE;
        }

        $framework = ($this->detectionService ?? FrameworkDetectionService::createDefault())->detect();
        $registry = new ProjectRegistry();
        $projectRoot = $framework?->getProjectRoot() ?? $this->projectRootFromContext($registry);
        if ($projectRoot === null || $projectRoot === '') {
            $output->writeln('<error>Не удалось определить директорию проекта.</error>');

            return Command::FAILURE;
        }

        $metadataDirectory = join_path($projectRoot, '.docker-cli');
        $metaFile = join_path($metadataDirectory, 'project.yaml');
        if (!is_file($metaFile)) {
            $output->writeln(sprintf('<error>Файл "%s" не найден.</error>', $metaFile));

            return Command::FAILURE;
        }

        $projectName = $this->readProjectName($metaFile);
        if ($projectName === null || $projectName === '') {
            $output->writeln(sprintf('<error>В файле "%s" не найдено имя проекта.</error>', $metaFile));

            return Command::FAILURE;
        }

        if ($input->getOption('drop')) {
            try {
                $dropCode = ($this->dataInitializer ?? new DataInitializer())->drop($projectName, $output);
            } catch (MissingConfigException $exception) {
                $output->writeln(sprintf('<error>Системная конфигурация не инициализирована. Отсутствуют файлы: %s.</error>', implode(', ', $exception->missingFiles())));

                return Command::FAILURE;
            }
            if ($dropCode !== Command::SUCCESS) {
                $output->writeln(sprintf('<error>Не удалось удалить базы данных и пользователей проекта "%s". Регистрация проекта сохранена.</error>', $projectName));

                return $dropCode;
            }
        }

        $projectDirectory = join_path($this->projectsDirectory(), $projectName);
        if (is_dir($projectDirectory)) {
            $this->removeDirectory($projectDirectory);
        }

        unlink($metaFile);
        $metadataFiles = scandir($metadataDirectory);
        if ($metadataFiles !== false && array_diff($metadataFiles, ['.', '..']) === []) {
            rmdir($metadataDirectory);
        }
        (new OpenRestyHostRenderer())->render();

        if (!$input->getOption('no-restart')) {
            $restartCode = (new ConfigurableServicesRestarter())->restart($output);
            if ($restartCode !== Command::SUCCESS) {
                return $restartCode;
            }
        }

        $output->writeln(sprintf('<info>Регистрация проекта "%s" удалена.</info>', $projectName));

        return Command::SUCCESS;
    }

    private function projectRootFromContext(ProjectRegistry $registry): ?string
    {
        $projectName = $registry->projectNameFromContext();
        if ($projectName === null || !$registry->hasProject($projectName)) {
            return null;
        }

        $projectConfig = $registry->readProjectConfig($projectName);
        $projectRoot = $projectConfig['data']['project']['root'] ?? null;

        return is_string($projectRoot) && $projectRoot !== '' ? $projectRoot : null;
    }

    private function projectsDirectory(): string
    {
        $home = getenv('HOME') ?: null;
        if ($home === null) {
            throw new \RuntimeException('Unable to determine HOME directory.');
        }

        return join_path($home, '.config', 'docker-cli', 'state', 'projects');
    }

    private function readProjectName(string $file): ?string
    {
        $data = Yaml::parseFile($file);
        if (!is_array($data)) {
            return null;
        }

        $name = $data['data']['project']['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
