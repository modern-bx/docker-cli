<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Project\DataInitializer;
use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DataDropCommand extends Command
{
    public function __construct(
        private readonly ?ProjectRegistry $registry = null,
        private readonly ?DataInitializer $initializer = null,
    ) {
        parent::__construct('data:drop');
        $this->setDescription('Удалить БД и пользователя проекта во всех доступных СУБД.');
        $this->addArgument('project-name', InputArgument::REQUIRED, 'Кодовое имя зарегистрированного проекта.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectName = $input->getArgument('project-name');
        if (!is_string($projectName) || $projectName === '') {
            $output->writeln('<error>Укажите код зарегистрированного проекта.</error>');
            return Command::FAILURE;
        }

        $registry = $this->registry ?? new ProjectRegistry();
        if (!$registry->hasProject($projectName)) {
            $output->writeln(sprintf('<error>Проект "%s" не зарегистрирован.</error>', $projectName));
            return Command::FAILURE;
        }

        try {
            $code = ($this->initializer ?? new DataInitializer())->drop($projectName, $output);
        } catch (MissingConfigException $exception) {
            $output->writeln(sprintf('<error>Системная конфигурация не инициализирована. Отсутствуют файлы: %s.</error>', implode(', ', $exception->missingFiles())));
            return Command::FAILURE;
        }

        if ($code === Command::SUCCESS) {
            $output->writeln(sprintf('<info>Данные проекта "%s" удалены.</info>', $projectName));
        }

        return $code;
    }
}
