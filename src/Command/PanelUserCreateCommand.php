<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\SystemCompose;
use DockerCli\Panel\PanelPasswordGenerator;
use DockerCli\Panel\UserRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PanelUserCreateCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('panel:user-create');
        $this->setDescription('Создать пользователя административной панели.');
        $this->addArgument('login', InputArgument::REQUIRED, 'Логин пользователя (email).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $compose = new SystemCompose();
        $salt = $compose->envValue('PANEL_PASSWORD_SALT');
        if ($salt === '') {
            $this->writeMessage($output, '<error>Соль паролей не настроена. Выполните `docker-cli config:init`.</error>');
            return Command::FAILURE;
        }

        try {
            $value = $input->getArgument('login');
            $login = UserRepository::normalizeLogin(is_string($value) ? $value : '');
        } catch (\InvalidArgumentException $exception) {
            $this->writeMessage($output, '<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        }
        $password = (new PanelPasswordGenerator())->generate();
        $repository = new UserRepository($salt);
        if (!$repository->add($login, $password)) {
            $this->writeMessage($output, sprintf('<error>Пользователь %s уже существует.</error>', $login));
            return Command::FAILURE;
        }
        $this->writeMessage($output, sprintf('<info>Пользователь %s создан.</info>', $login));
        $this->writeMessage($output, sprintf('<info>Пароль: %s</info>', $password));
        return Command::SUCCESS;
    }
}
