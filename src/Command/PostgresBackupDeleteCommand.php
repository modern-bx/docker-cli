<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function DockerCli\Util\join_path;

final class PostgresBackupDeleteCommand extends AbstractCommand
{
    public function __construct(private readonly ?ProjectRegistry $registry = null)
    {
        parent::__construct('postgres:backup-delete');
        $this->setDescription('Удалить PostgreSQL-бэкап текущего проекта.');
        $this->addArgument('backup', InputArgument::REQUIRED, 'Короткое имя бэкапа.');
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
        $backupRoot = realpath(join_path($root, '.docker-cli', 'backups', 'postgres'));
        $backup = $backupRoot === false ? false : realpath(join_path($backupRoot, $name));
        if ($backup === false || !is_dir($backup) || dirname($backup) !== $backupRoot) {
            $this->writeMessage($output, sprintf('<error>Бэкап "%s" не найден.</error>', $name));
            return Command::FAILURE;
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
