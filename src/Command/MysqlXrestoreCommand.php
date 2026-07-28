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

final class MysqlXrestoreCommand extends Command
{
    public function __construct(private readonly ?ProjectRegistry $registry = null, private readonly ?MysqlXtrabackup $xtrabackup = null)
    {
        parent::__construct('mysql:xrestore');
        $this->setDescription('Максимально быстро восстановить физический бэкап MySQL.');
        $this->addArgument('path', InputArgument::REQUIRED, 'Директория, созданная mysql:xbackup.');
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Код зарегистрированного проекта.');
        $this->addOption('parallel', 'j', InputOption::VALUE_REQUIRED, 'Число параллельных потоков.', '4');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Подтвердить остановку MySQL и полную замену его данных.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->registry ?? new ProjectRegistry();
        $project = $input->getOption('project') ?: $registry->projectNameFromContext();
        if (!is_string($project) || !$registry->hasProject($project)) {
            $output->writeln('<error>Укажите зарегистрированный проект через --project или запустите команду из проекта.</error>');
            return Command::FAILURE;
        }
        if (!$input->getOption('force')) {
            $output->writeln('<error>Восстановление заменит весь экземпляр MySQL (включая БД других проектов). Повторите с --force.</error>');
            return Command::FAILURE;
        }
        $parallel = filter_var($input->getOption('parallel'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($parallel === false) {
            $output->writeln('<error>Опция --parallel должна быть положительным целым числом.</error>');
            return Command::INVALID;
        }
        $path = realpath((string) $input->getArgument('path'));
        if ($path === false || !is_file($path . '/xtrabackup_checkpoints')) {
            $output->writeln('<error>Указанная директория не является бэкапом XtraBackup.</error>');
            return Command::FAILURE;
        }
        $metadata = json_decode((string) @file_get_contents($path . '/docker-cli.json'), true);
        if (is_array($metadata) && isset($metadata['project']) && $metadata['project'] !== $project) {
            $output->writeln(sprintf('<error>Бэкап создан в контексте проекта "%s", а выбран проект "%s".</error>', $metadata['project'], $project));
            return Command::FAILURE;
        }
        try {
            $code = ($this->xtrabackup ?? new MysqlXtrabackup())->restore($path, $parallel, $output);
        } catch (MissingConfigException $e) {
            $output->writeln('<error>Системная конфигурация не инициализирована.</error>');
            return Command::FAILURE;
        }
        if ($code === Command::SUCCESS) {
            $output->writeln(sprintf('<info>MySQL восстановлен из "%s" и запущен.</info>', $path));
        }
        return $code;
    }
}
