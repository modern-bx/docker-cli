<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Project\MysqlRecovery;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MysqlRecoverCommand extends AbstractCommand
{
    public function __construct(private readonly ?MysqlRecovery $recovery = null)
    {
        parent::__construct('mysql:recover');
        $this->setDescription('Извлечь пользовательские базы из каталога data остановленного MySQL-инстанса.');
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Каталог data исходного MySQL-инстанса.', '.');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Каталог для дампов mydumper.', '.');
        $this->addOption('database', null, InputOption::VALUE_REQUIRED, 'Имена экспортируемых баз через запятую.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $from = $this->absolutePath((string) $input->getOption('from'));
        $to = $this->absolutePath((string) $input->getOption('to'));
        $databaseOption = $input->getOption('database');
        $databases = $databaseOption === null ? [] : array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $databaseOption)))));
        if ($databaseOption !== null && $databases === []) {
            $this->writeMessage($output, '<error>Опция --database должна содержать хотя бы одно имя базы.</error>');
            return Command::INVALID;
        }
        try {
            ($this->recovery ?? new MysqlRecovery())->recover($from, $to, $databases, $output);
            $this->writeMessage($output, '<info>Восстановление дампов завершено.</info>');
            return Command::SUCCESS;
        } catch (\InvalidArgumentException $exception) {
            $this->writeMessage($output, '<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        } catch (\Throwable $exception) {
            $this->writeMessage($output, '<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function absolutePath(string $path): string
    {
        if ($path === '') return getcwd() ?: '.';
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? rtrim($path, DIRECTORY_SEPARATOR) : rtrim((getcwd() ?: '.') . DIRECTORY_SEPARATOR . $path, DIRECTORY_SEPARATOR);
    }
}
