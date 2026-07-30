<?php
declare(strict_types=1);
namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Project\DatabaseManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DataDatabaseCreateCommand extends AbstractCommand
{
    use DatabaseCommandInput;
    public function __construct(private readonly ?DatabaseManager $manager = null) { parent::__construct('data:database-create'); $this->setDescription('Создать базу данных и выдать пользователям права.'); $this->addArgument('database', InputArgument::REQUIRED); $this->addOption('user', null, InputOption::VALUE_REQUIRED, 'Пользователи через запятую.'); $this->addOption('dbms', null, InputOption::VALUE_REQUIRED, 'mysql или postgres.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dbms = $this->selectedDbms($input->getOption('dbms')); $database = trim((string) $input->getArgument('database'));
        if ($dbms === null || $database === '') { $output->writeln('<error>Укажите непустое имя базы и поддерживаемую СУБД: mysql или postgres.</error>'); return Command::INVALID; }
        try { return ($this->manager ?? new DatabaseManager())->createDatabases($dbms, $database, $this->commaList($input->getOption('user')), $output); }
        catch (MissingConfigException $e) { $output->writeln('<error>Системная конфигурация не инициализирована.</error>'); return Command::FAILURE; }
    }
}
