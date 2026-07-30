<?php
declare(strict_types=1);
namespace DockerCli\Command;

use DockerCli\Project\DatabaseManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

final class DataDatabaseDeleteCommand extends AbstractCommand
{
    use DatabaseCommandInput;
    public function __construct(private readonly ?DatabaseManager $manager = null) { parent::__construct('data:database-delete'); $this->setDescription('Удалить базы данных.'); $this->addArgument('databases', InputArgument::REQUIRED, 'Имена через запятую.'); $this->addOption('force', 'f', InputOption::VALUE_NONE); $this->addOption('dbms', null, InputOption::VALUE_REQUIRED, 'mysql или postgres.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dbms = $this->selectedDbms($input->getOption('dbms')); $items = $this->commaList($input->getArgument('databases'));
        if ($dbms === null || $items === []) { $this->writeMessage($output, '<error>Укажите базы и поддерживаемую СУБД: mysql или postgres.</error>'); return Command::INVALID; }
        if (!$input->getOption('force') && !$this->getHelper('question')->ask($input, $output, new ConfirmationQuestion('Удалить указанные базы? [y/N] ', false))) { $this->writeMessage($output, '<comment>Операция отменена.</comment>'); return Command::SUCCESS; }
        return ($this->manager ?? new DatabaseManager())->deleteDatabases($dbms, $items, $output);
    }
}
