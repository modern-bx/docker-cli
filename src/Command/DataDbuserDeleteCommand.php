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

final class DataDbuserDeleteCommand extends AbstractCommand
{
    use DatabaseCommandInput;
    public function __construct(private readonly ?DatabaseManager $manager = null) { parent::__construct('data:dbuser-delete'); $this->setDescription('Удалить пользователей БД.'); $this->addArgument('users', InputArgument::REQUIRED, 'Имена через запятую.'); $this->addOption('force', 'f', InputOption::VALUE_NONE); $this->addOption('dbms', null, InputOption::VALUE_REQUIRED, 'mysql или postgres.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dbms = $this->selectedDbms($input->getOption('dbms')); $items = $this->commaList($input->getArgument('users'));
        if ($dbms === null || $items === []) { $output->writeln('<error>Укажите пользователей и поддерживаемую СУБД: mysql или postgres.</error>'); return Command::INVALID; }
        if (!$input->getOption('force') && !$this->getHelper('question')->ask($input, $output, new ConfirmationQuestion('Удалить указанных пользователей? [y/N] ', false))) { $output->writeln('<comment>Операция отменена.</comment>'); return Command::SUCCESS; }
        return ($this->manager ?? new DatabaseManager())->deleteUsers($dbms, $items, $output);
    }
}
