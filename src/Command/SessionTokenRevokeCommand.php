<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Panel\TokenRepository;
use DockerCli\Panel\UserRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SessionTokenRevokeCommand extends Command
{
    public function __construct()
    {
        parent::__construct('session:token-revoke');
        $this->setDescription('Отозвать все токены сессий указанных пользователей панели.');
        $this->addArgument('users', InputArgument::REQUIRED, 'Логины пользователей через запятую.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $value = $input->getArgument('users');
        $users = array_values(array_filter(array_map('trim', explode(',', is_string($value) ? $value : ''))));
        if ($users === []) {
            $output->writeln('<error>Укажите хотя бы одного пользователя.</error>');
            return Command::INVALID;
        }
        try {
            $users = array_map(UserRepository::normalizeLogin(...), $users);
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        }
        $count = (new TokenRepository())->revoke($users);
        $output->writeln(sprintf('<info>Отозвано токенов: %d.</info>', $count));
        return Command::SUCCESS;
    }
}
