<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Project\MysqlXtrabackup;
use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MysqlXbackupCommand extends Command
{
    public function __construct(private readonly ?ProjectRegistry $registry = null, private readonly ?MysqlXtrabackup $xtrabackup = null)
    {
        parent::__construct('mysql:xbackup');
        $this->setDescription('Создать быстрый физический бэкап MySQL средствами Percona XtraBackup.');
        $this->addArgument('path', InputArgument::OPTIONAL, 'Директория бэкапа (по умолчанию .docker-cli/backups/mysql/<дата>).');
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Код зарегистрированного проекта.');
        $this->addOption('parallel', 'j', InputOption::VALUE_REQUIRED, 'Число параллельных потоков.', '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->registry ?? new ProjectRegistry();
        $project = $input->getOption('project') ?: $registry->projectNameFromContext();
        if (!is_string($project) || !$registry->hasProject($project)) {
            $output->writeln('<error>Укажите зарегистрированный проект через --project или запустите команду из проекта.</error>');
            return Command::FAILURE;
        }
        $parallel = filter_var($input->getOption('parallel'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($parallel === false) {
            $output->writeln('<error>Опция --parallel должна быть положительным целым числом.</error>');
            return Command::INVALID;
        }
        $path = $input->getArgument('path');
        $path = is_string($path) && $path !== '' ? $path : sprintf('.docker-cli/backups/mysql/%s-%s', $project, date('Ymd-His'));
        $path = $this->absolutePath($path);
        try {
            $code = ($this->xtrabackup ?? new MysqlXtrabackup())->backup($path, $parallel, $output);
        } catch (MissingConfigException $e) {
            $output->writeln('<error>Системная конфигурация не инициализирована.</error>');
            return Command::FAILURE;
        }
        if ($code === Command::SUCCESS) {
            file_put_contents($path . '/docker-cli.json', json_encode(['project' => $project, 'createdAt' => date(DATE_ATOM), 'scope' => 'mysql-instance'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            $output->writeln(sprintf('<info>Физический бэкап MySQL записан в "%s".</info>', $path));
        }
        return $code;
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? rtrim($path, DIRECTORY_SEPARATOR) : rtrim((getcwd() ?: '.') . DIRECTORY_SEPARATOR . $path, DIRECTORY_SEPARATOR);
    }
}
