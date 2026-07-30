<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Project\DataInitializer;
use DockerCli\Project\ProjectDatabaseConfig;
use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DataInitCommand extends AbstractCommand
{
    public function __construct(
        private readonly ?ProjectRegistry $registry = null,
        private readonly ?DataInitializer $initializer = null,
    ) {
        parent::__construct('data:init');
        $this->setDescription('Создать БД и пользователя проекта во всех доступных СУБД.');
        $this->addArgument('project', InputArgument::OPTIONAL, 'Кодовое имя зарегистрированного проекта.');
        $this->addOption('rebuild', null, InputOption::VALUE_NONE, 'Удалить существующие БД и пользователей перед созданием.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->registry ?? new ProjectRegistry();
        $projectName = $this->resolveProjectName($input, $registry);
        if ($projectName === null) {
            $this->writeMessage($output, '<error>Укажите код зарегистрированного проекта или запустите команду в директории зарегистрированного проекта.</error>');
            return Command::FAILURE;
        }

        if (!$registry->hasProject($projectName)) {
            $this->writeMessage($output, sprintf('<error>Проект "%s" не зарегистрирован.</error>', $projectName));
            return Command::FAILURE;
        }

        $config = (new ProjectDatabaseConfig())->ensure($registry->readProjectConfig($projectName));
        $registry->writeProjectConfig($projectName, $config);

        $mysqlPassword = $config['data']['databases']['mysql']['password'] ?? null;
        $postgresPassword = $config['data']['databases']['postgres']['password'] ?? null;
        if (!is_string($mysqlPassword) || !is_string($postgresPassword)) {
            throw new \RuntimeException('Database passwords are missing from project config.');
        }

        try {
            $code = ($this->initializer ?? new DataInitializer())->initialize($projectName, $mysqlPassword, $postgresPassword, (bool) $input->getOption('rebuild'), $output);
        } catch (MissingConfigException $exception) {
            $this->writeMessage($output, sprintf('<error>Системная конфигурация не инициализирована. Отсутствуют файлы: %s.</error>', implode(', ', $exception->missingFiles())));
            return Command::FAILURE;
        }

        if ($code === Command::SUCCESS) {
            $this->writeMessage($output, sprintf('<info>Данные проекта "%s" инициализированы.</info>', $projectName));
        }

        return $code;
    }

    private function resolveProjectName(InputInterface $input, ProjectRegistry $registry): ?string
    {
        $projectName = $input->getArgument('project');
        if (is_string($projectName) && $projectName !== '') {
            return $projectName;
        }

        return $registry->projectNameFromContext();
    }
}
