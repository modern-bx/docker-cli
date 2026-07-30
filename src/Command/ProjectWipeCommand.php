<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ProjectWipeCommand extends AbstractCommand
{
    public function __construct(private readonly ?CommandContext $context = null, private readonly ?ProjectRegistry $registry = null)
    {
        parent::__construct('project:wipe');
        $this->setDescription('Удалить все файлы проекта, кроме директории .docker-cli.');
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Кодовое имя зарегистрированного проекта.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->registry ?? new ProjectRegistry();
        $projectName = $input->getOption('project');
        if (!is_string($projectName) || $projectName === '') {
            $projectName = $registry->projectNameFromContext();
        }

        if ($projectName === null) {
            $output->writeln('<error>Укажите код проекта через --project или запустите команду в директории зарегистрированного проекта.</error>');
            return Command::FAILURE;
        }

        if (!$registry->hasProject($projectName)) {
            $output->writeln(sprintf('<error>Проект "%s" не зарегистрирован.</error>', $projectName));
            return Command::FAILURE;
        }
        if ($registry->isProjectProtected($projectName)) {
            $output->writeln(sprintf('<error>Проект "%s" защищен. Изменение его данных запрещено.</error>', $projectName));
            return Command::FAILURE;
        }

        $config = $registry->readProjectConfig($projectName);
        $projectRoot = $config['data']['project']['root'] ?? null;
        if (!is_string($projectRoot) || $projectRoot === '' || !is_dir($projectRoot)) {
            $output->writeln(sprintf('<error>Директория проекта "%s" не найдена.</error>', $projectName));
            return Command::FAILURE;
        }

        $projectRoot = realpath($projectRoot);
        if ($projectRoot === false || $projectRoot === DIRECTORY_SEPARATOR) {
            $output->writeln('<error>Отказ очистки: небезопасная директория проекта.</error>');
            return Command::FAILURE;
        }
        if (!is_dir($projectRoot . DIRECTORY_SEPARATOR . '.docker-cli')) {
            $output->writeln(sprintf('<error>В директории проекта "%s" не найдена директория .docker-cli.</error>', $projectName));
            return Command::FAILURE;
        }

        try {
            foreach (scandir($projectRoot) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === '.docker-cli') {
                    continue;
                }
                $this->removePath($projectRoot . DIRECTORY_SEPARATOR . $entry);
            }
        } catch (\RuntimeException $exception) {
            $output->writeln(sprintf('<error>Не удалось очистить проект "%s": %s</error>', $projectName, $exception->getMessage()));
            return Command::FAILURE;
        }

        ($this->context ?? CommandContext::fromEnvironment($this))->addMessage(
            new Message(sprintf("Файлы проекта **%s** успешно удалены. Служебная директория `.docker-cli` сохранена.", $projectName), notify: true),
        );

        return Command::SUCCESS;
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            if (!unlink($path)) {
                throw new \RuntimeException(sprintf('Не удалось удалить "%s".', $path));
            }
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removePath($path . DIRECTORY_SEPARATOR . $entry);
        }

        if (!rmdir($path)) {
            throw new \RuntimeException(sprintf('Не удалось удалить директорию "%s".', $path));
        }
    }
}
