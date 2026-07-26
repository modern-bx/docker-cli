<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\SystemCompose;
use DockerCli\Panel\UserRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PanelUserDeleteCommand extends Command
{
    public function __construct()
    {
        parent::__construct('panel:user-delete');
        $this->setDescription('Удалить пользователя административной панели.');
        $this->addArgument('login', InputArgument::REQUIRED, 'Логин пользователя (email).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $login = $input->getArgument('login');
        try {
            $login = UserRepository::normalizeLogin(is_string($login) ? $login : '');
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        }
        $salt = (new SystemCompose())->envValue('PANEL_PASSWORD_SALT');
        if ($salt === '') {
            $output->writeln('<error>Соль паролей не настроена. Выполните `docker-cli config:init`.</error>');
            return Command::FAILURE;
        }
        $repository = new UserRepository($salt);
        if (!$repository->delete($login)) {
            $output->writeln(sprintf('<comment>Пользователь %s не существует.</comment>', $login));
            return Command::SUCCESS;
        }
        $output->writeln(sprintf('<info>Пользователь %s удалён.</info>', $login));
        return Command::SUCCESS;
    }
}
