<?php
declare(strict_types=1);
namespace DockerCli\Command;

use DockerCli\Project\DatabaseManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DataDbuserCreateCommand extends AbstractCommand
{
    use DatabaseCommandInput;
    public function __construct(private readonly ?DatabaseManager $manager = null) { parent::__construct('data:dbuser-create'); $this->setDescription('Создать пользователя БД и выдать права.'); $this->addArgument('user', InputArgument::REQUIRED); $this->addOption('database', null, InputOption::VALUE_REQUIRED, 'Базы через запятую.'); $this->addOption('dbms', null, InputOption::VALUE_REQUIRED, 'mysql или postgres.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dbms = $this->selectedDbms($input->getOption('dbms')); $user = trim((string) $input->getArgument('user'));
        if ($dbms === null || $user === '') { $this->writeMessage($output, '<error>Укажите пользователя и поддерживаемую СУБД: mysql или postgres.</error>'); return Command::INVALID; }
        return ($this->manager ?? new DatabaseManager())->createUser($dbms, $user, $this->commaList($input->getOption('database')), $output);
    }
}
