<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Project\BackupStorageLocator;
use DockerCli\Project\ProjectRegistry;
use DockerCli\Project\TreeArchiveManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function DockerCli\Util\join_path;

final class TreeDumpCommand extends AbstractCommand
{
    public function __construct(
        private readonly ?ProjectRegistry $registry = null,
        private readonly ?BackupStorageLocator $storageLocator = null,
        private readonly ?TreeArchiveManager $archiveManager = null,
    ) {
        parent::__construct('tree:dump');
        $this->setDescription('Создать файловый бэкап проекта в tar-формате.');
        $this->addOption('location', null, InputOption::VALUE_REQUIRED, 'Код централизованного хранилища бэкапов.');
        $this->addOption('compress', null, InputOption::VALUE_REQUIRED, 'Архиватор: gzip, bzip2, xz, zstd, lz4 или zip.');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Короткое имя директории бэкапа.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->registry ?? new ProjectRegistry();
        $project = $registry->projectNameFromContext();
        if ($project === null || !$registry->hasProject($project)) {
            $this->writeMessage($output, '<error>Запустите команду из директории зарегистрированного проекта.</error>');
            return Command::FAILURE;
        }
        $name = $input->getOption('name') ?? sprintf('%s-%s', $project, date('Ymd-His'));
        if (!is_string($name) || $name === '' || basename($name) !== $name || in_array($name, ['.', '..'], true)) {
            $this->writeMessage($output, '<error>Опция --name должна содержать корректное короткое имя директории бэкапа.</error>');
            return Command::INVALID;
        }
        $location = $input->getOption('location');
        if ($location !== null && (!is_string($location) || $location === '')) {
            $this->writeMessage($output, '<error>Опция --location должна содержать код хранилища бэкапов.</error>');
            return Command::INVALID;
        }
        $compressor = $input->getOption('compress');
        if ($compressor !== null && (!is_string($compressor) || $compressor === '')) {
            $this->writeMessage($output, '<error>Опция --compress должна содержать название архиватора.</error>');
            return Command::INVALID;
        }
        $root = $registry->readProjectConfig($project)['data']['project']['root'] ?? null;
        if (!is_string($root) || $root === '' || !is_dir($root)) {
            $this->writeMessage($output, '<error>Конфигурация проекта повреждена.</error>');
            return Command::FAILURE;
        }

        try {
            $backupRoot = $location === null
                ? join_path($root, '.docker-cli', 'backups', 'tree')
                : ($this->storageLocator ?? new BackupStorageLocator())->treeDirectory($location);
            $backupDirectory = join_path($backupRoot, $name);
            $archive = ($this->archiveManager ?? new TreeArchiveManager())->dump($root, $backupDirectory, $compressor);
            $metadata = ['project' => $project, 'createdAt' => date(DATE_ATOM), 'archive' => $archive];
            if (file_put_contents(join_path($backupDirectory, 'docker-cli.json'), json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL) === false) {
                throw new \RuntimeException('Не удалось записать метаданные бэкапа.');
            }
        } catch (\InvalidArgumentException $exception) {
            $this->writeMessage($output, '<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        } catch (\RuntimeException $exception) {
            $this->writeMessage($output, '<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        CommandContext::fromEnvironment($this, $output)->addMessage(new Message(
            sprintf('Файловый бэкап проекта "%s" создан: "%s".', $project, $name),
            MessageLevel::Info,
            notify: true,
        ));
        return Command::SUCCESS;
    }
}
