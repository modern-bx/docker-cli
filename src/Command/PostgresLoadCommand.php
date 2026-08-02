<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Project\PostgresDumpLoader;
use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PostgresLoadCommand extends AbstractCommand
{
    public function __construct(private readonly ?ProjectRegistry $registry = null, private readonly ?PostgresDumpLoader $dumpLoader = null)
    {
        parent::__construct('postgres:load');
        $this->setDescription('Параллельно восстановить PostgreSQL из directory-бэкапа.');
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Путь к директории, созданной postgres:dump.');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Короткое имя директории бэкапа.');
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Код зарегистрированного проекта.');
        $this->addOption('jobs', 'j', InputOption::VALUE_REQUIRED, 'Число параллельных процессов.', '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->registry ?? new ProjectRegistry();
        $project = $input->getOption('project') ?: $registry->projectNameFromContext();
        if (!is_string($project) || !$registry->hasProject($project)) {
            $this->writeMessage($output, '<error>Укажите зарегистрированный проект через --project или запустите команду из проекта.</error>');
            return Command::FAILURE;
        }
        if ($registry->isProjectProtected($project)) {
            $this->writeMessage($output, sprintf('<error>Проект "%s" защищен. Изменение его данных запрещено.</error>', $project));
            return Command::FAILURE;
        }
        $jobs = filter_var($input->getOption('jobs'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($jobs === false) {
            $this->writeMessage($output, '<error>Опция --jobs должна быть положительным целым числом.</error>');
            return Command::INVALID;
        }
        $config = $registry->readProjectConfig($project)['data']['databases']['postgres'] ?? [];
        $database = $config['database'] ?? $project;
        $owner = $config['username'] ?? $database;
        if (!is_string($database) || $database === '' || !is_string($owner) || $owner === '') {
            $this->writeMessage($output, sprintf('<error>В конфигурации проекта "%s" некорректно задана база PostgreSQL.</error>', $project));
            return Command::FAILURE;
        }
        $name = $input->getOption('name');
        $path = $input->getOption('path');
        if (($name === null) === ($path === null)) {
            $this->writeMessage($output, '<error>Укажите ровно одну из опций --name или --path.</error>');
            return Command::INVALID;
        }
        if ($name !== null && (!is_string($name) || $name === '' || basename($name) !== $name)) {
            $this->writeMessage($output, '<error>Опция --name должна содержать короткое имя директории бэкапа.</error>');
            return Command::INVALID;
        }
        if ($path !== null && (!is_string($path) || $path === '')) {
            $this->writeMessage($output, '<error>Опция --path должна содержать путь к директории бэкапа.</error>');
            return Command::INVALID;
        }
        $path = realpath($path ?? sprintf('.docker-cli/backups/postgres/%s', $name));
        if ($path === false || !is_file($path . '/toc.dat')) {
            $this->writeMessage($output, '<error>Указанная директория не является directory-бэкапом pg_dump.</error>');
            return Command::FAILURE;
        }
        $metadata = json_decode((string) @file_get_contents($path . '/docker-cli.json'), true);
        if (is_array($metadata) && (($metadata['project'] ?? null) !== $project || ($metadata['database'] ?? null) !== $database)) {
            $this->writeMessage($output, '<error>Бэкап создан для другого проекта или базы данных.</error>');
            return Command::FAILURE;
        }
        try {
            $code = ($this->dumpLoader ?? new PostgresDumpLoader())->load($database, $owner, $path, $jobs, $output);
        } catch (MissingConfigException) {
            $this->writeMessage($output, '<error>Системная конфигурация не инициализирована.</error>');
            return Command::FAILURE;
        }
        if ($code === Command::SUCCESS) {
            CommandContext::fromEnvironment($this, $output)->addMessage(new Message(
                sprintf('PostgreSQL-база "%s" проекта "%s" восстановлена из бэкапа "%s".', $database, $project, basename($path)),
                MessageLevel::Info,
                notify: true,
            ));
        }

        return $code;
    }
}
