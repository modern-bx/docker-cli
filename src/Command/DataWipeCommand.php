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

final class DataWipeCommand extends Command
{
    public function __construct(
        private readonly ?ProjectRegistry $registry = null,
        private readonly ?DataInitializer $initializer = null,
    ) {
        parent::__construct('data:wipe');
        $this->setDescription('Очистить все таблицы в БД проекта, не удаляя БД и пользователей.');
        $this->addArgument('project-name', InputArgument::OPTIONAL, 'Кодовое имя зарегистрированного проекта.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->registry ?? new ProjectRegistry();
        $projectName = $this->resolveProjectName($input, $registry);
        if ($projectName === null) {
            $output->writeln('<error>Укажите код зарегистрированного проекта или запустите команду в директории зарегистрированного проекта.</error>');
            return Command::FAILURE;
        }

        if (!$registry->hasProject($projectName)) {
            $output->writeln(sprintf('<error>Проект "%s" не зарегистрирован.</error>', $projectName));
            return Command::FAILURE;
        }

        $config = $registry->readProjectConfig($projectName);
        $mysqlDatabase = $config['data']['databases']['mysql']['database'] ?? $projectName;
        $postgresDatabase = $config['data']['databases']['postgres']['database'] ?? $projectName;
        if (!is_string($mysqlDatabase) || $mysqlDatabase === '' || !is_string($postgresDatabase) || $postgresDatabase === '') {
            $output->writeln(sprintf('<error>В конфигурации проекта "%s" не заданы БД MySQL или PostgreSQL.</error>', $projectName));
            return Command::FAILURE;
        }

        try {
            $code = ($this->initializer ?? new DataInitializer())->wipe($mysqlDatabase, $postgresDatabase, $output);
        } catch (MissingConfigException $exception) {
            $output->writeln(sprintf('<error>Системная конфигурация не инициализирована. Отсутствуют файлы: %s.</error>', implode(', ', $exception->missingFiles())));
            return Command::FAILURE;
        }

        if ($code === Command::SUCCESS) {
            $output->writeln(sprintf('<info>Все таблицы в БД проекта "%s" удалены.</info>', $projectName));
        }

        return $code;
    }

    private function resolveProjectName(InputInterface $input, ProjectRegistry $registry): ?string
    {
        $projectName = $input->getArgument('project-name');
        if (is_string($projectName) && $projectName !== '') {
            return $projectName;
        }

        return $registry->projectNameFromContext();
    }
}
