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

final class PostgresDumpCommand extends AbstractCommand
{
    public function __construct(private readonly ?ProjectRegistry $registry = null, private readonly ?PostgresDumpLoader $dumpLoader = null)
    {
        parent::__construct('postgres:dump');
        $this->setDescription('Создать быстрый параллельный бэкап PostgreSQL в directory-формате.');
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Код зарегистрированного проекта.');
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Родительская директория бэкапа.', './.docker-cli/backups/postgres/');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Короткое имя директории бэкапа.');
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
        $jobs = filter_var($input->getOption('jobs'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($jobs === false) {
            $this->writeMessage($output, '<error>Опция --jobs должна быть положительным целым числом.</error>');
            return Command::INVALID;
        }
        $database = $registry->readProjectConfig($project)['data']['databases']['postgres']['database'] ?? $project;
        if (!is_string($database) || $database === '') {
            $this->writeMessage($output, sprintf('<error>В конфигурации проекта "%s" не задана база PostgreSQL.</error>', $project));
            return Command::FAILURE;
        }
        $parent = rtrim($this->absolutePath((string) $input->getOption('path')), DIRECTORY_SEPARATOR);
        $name = $input->getOption('name');
        if ($name !== null && (!is_string($name) || $name === '' || basename($name) !== $name)) {
            $this->writeMessage($output, '<error>Опция --name должна содержать короткое имя директории бэкапа.</error>');
            return Command::INVALID;
        }
        $path = $parent . DIRECTORY_SEPARATOR . ($name ?? sprintf('%s-%s', $project, date('Ymd-His')));
        try {
            $code = ($this->dumpLoader ?? new PostgresDumpLoader())->dump($database, $path, $jobs, $output);
        } catch (MissingConfigException) {
            $this->writeMessage($output, '<error>Системная конфигурация не инициализирована.</error>');
            return Command::FAILURE;
        }
        if ($code === Command::SUCCESS) {
            file_put_contents($path . '/docker-cli.json', json_encode(['project' => $project, 'database' => $database, 'createdAt' => date(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            CommandContext::fromEnvironment($this, $output)->addMessage(new Message(
                sprintf('Бэкап PostgreSQL-базы "%s" проекта "%s" создан: "%s".', $database, $project, $path),
                MessageLevel::Info,
                notify: true,
            ));
        }

        return $code;
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : (getcwd() ?: '.') . DIRECTORY_SEPARATOR . $path;
    }
}
