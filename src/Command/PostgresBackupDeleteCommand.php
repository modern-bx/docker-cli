<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Project\ProjectRegistry;
use DockerCli\Project\BackupStorageLocator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function DockerCli\Util\join_path;

final class PostgresBackupDeleteCommand extends AbstractCommand
{
    public function __construct(private readonly ?ProjectRegistry $registry = null, private readonly ?BackupStorageLocator $storageLocator = null)
    {
        parent::__construct('postgres:backup-delete');
        $this->setDescription('Удалить PostgreSQL-бэкап текущего проекта.');
        $this->addArgument('backup', InputArgument::REQUIRED, 'Короткое имя бэкапа.');
        $this->addOption('location', null, InputOption::VALUE_REQUIRED, 'Код централизованного хранилища бэкапов.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->registry ?? new ProjectRegistry();
        $project = $registry->projectNameFromContext();
        if ($project === null || !$registry->hasProject($project)) {
            $this->writeMessage($output, '<error>Запустите команду из директории зарегистрированного проекта.</error>');
            return Command::FAILURE;
        }

        $name = (string) $input->getArgument('backup');
        if ($name === '' || basename($name) !== $name || in_array($name, ['.', '..'], true)) {
            $this->writeMessage($output, '<error>Укажите корректное короткое имя бэкапа.</error>');
            return Command::INVALID;
        }
        $root = $registry->readProjectConfig($project)['data']['project']['root'] ?? null;
        if (!is_string($root) || $root === '') {
            $this->writeMessage($output, '<error>Конфигурация проекта повреждена.</error>');
            return Command::FAILURE;
        }
        $location = $input->getOption('location');
        if ($location !== null && (!is_string($location) || $location === '')) {
            $this->writeMessage($output, '<error>Опция --location должна содержать код хранилища бэкапов.</error>');
            return Command::INVALID;
        }
        try {
            $directory = $location === null ? join_path($root, '.docker-cli', 'backups', 'postgres') : ($this->storageLocator ?? new BackupStorageLocator())->databaseDirectory($location, 'postgres');
        } catch (\InvalidArgumentException $exception) {
            $this->writeMessage($output, '<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        }
        $backupRoot = realpath($directory);
        $backup = $backupRoot === false ? false : realpath(join_path($backupRoot, $name));
        if ($backup === false || !is_dir($backup) || dirname($backup) !== $backupRoot) {
            $this->writeMessage($output, sprintf('<error>Бэкап "%s" не найден.</error>', $name));
            return Command::FAILURE;
        }
        if ($location !== null) {
            $metadata = json_decode((string) @file_get_contents(join_path($backup, 'docker-cli.json')), true);
            if (!is_array($metadata) || ($metadata['project'] ?? null) !== $project) {
                $this->writeMessage($output, sprintf('<error>Бэкап "%s" не найден.</error>', $name));
                return Command::FAILURE;
            }
        }

        try {
            $this->removeDirectory($backup);
        } catch (\RuntimeException $exception) {
            $this->writeMessage($output, sprintf('<error>%s</error>', $exception->getMessage()));
            return Command::FAILURE;
        }
        CommandContext::fromEnvironment($this, $output)->addMessage(new Message(
            sprintf('PostgreSQL-бэкап "%s" проекта "%s" удалён.', $name, $project),
            MessageLevel::Info,
            notify: true,
        ));
        return Command::SUCCESS;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (new \FilesystemIterator($directory) as $item) {
            $path = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) $this->removeDirectory($path);
            elseif (!unlink($path)) throw new \RuntimeException(sprintf('Не удалось удалить "%s".', $path));
        }
        if (!rmdir($directory)) throw new \RuntimeException(sprintf('Не удалось удалить "%s".', $directory));
    }
}
