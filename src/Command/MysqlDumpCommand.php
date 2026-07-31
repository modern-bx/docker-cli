<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Project\MysqlDumpLoader;
use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MysqlDumpCommand extends AbstractCommand
{
    public function __construct(private readonly ?ProjectRegistry $registry = null, private readonly ?MysqlDumpLoader $dumpLoader = null)
    {
        parent::__construct('mysql:dump');
        $this->setDescription('Создать параллельный дамп MySQL-базы проекта через mydumper.');
        $this->addArgument('path', InputArgument::OPTIONAL, 'Директория дампа (по умолчанию .docker-cli/backups/mysql/<дата>).');
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Код зарегистрированного проекта.');
        $this->addOption('threads', 'j', InputOption::VALUE_REQUIRED, 'Число параллельных потоков.', '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->registry ?? new ProjectRegistry();
        $project = $input->getOption('project') ?: $registry->projectNameFromContext();
        if (!is_string($project) || !$registry->hasProject($project)) {
            $this->writeMessage($output, '<error>Укажите зарегистрированный проект через --project или запустите команду из проекта.</error>');
            return Command::FAILURE;
        }
        $threads = filter_var($input->getOption('threads'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($threads === false) {
            $this->writeMessage($output, '<error>Опция --threads должна быть положительным целым числом.</error>');
            return Command::INVALID;
        }
        $database = $registry->readProjectConfig($project)['data']['databases']['mysql']['database'] ?? $project;
        if (!is_string($database) || $database === '') {
            $this->writeMessage($output, sprintf('<error>В конфигурации проекта "%s" не задана база MySQL.</error>', $project));
            return Command::FAILURE;
        }
        $path = $input->getArgument('path');
        $path = is_string($path) && $path !== '' ? $path : sprintf('.docker-cli/backups/mysql/%s-%s', $project, date('Ymd-His'));
        $path = $this->absolutePath($path);
        try {
            $code = ($this->dumpLoader ?? new MysqlDumpLoader())->dump($database, $path, $threads, $output);
        } catch (MissingConfigException) {
            $this->writeMessage($output, '<error>Системная конфигурация не инициализирована.</error>');
            return Command::FAILURE;
        }
        if ($code === Command::SUCCESS) {
            file_put_contents($path . '/docker-cli.json', json_encode(['project' => $project, 'database' => $database, 'createdAt' => date(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            CommandContext::fromEnvironment($this, $output)->addMessage(new Message(
                sprintf('Бэкап MySQL-базы "%s" проекта "%s" создан: "%s".', $database, $project, basename($path)),
                MessageLevel::Info,
                notify: true,
            ));
        }
        return $code;
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? rtrim($path, DIRECTORY_SEPARATOR) : rtrim((getcwd() ?: '.') . DIRECTORY_SEPARATOR . $path, DIRECTORY_SEPARATOR);
    }
}
