<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\SystemCompose;
use DockerCli\Panel\PanelPasswordGenerator;
use DockerCli\Panel\TokenRepository;
use DockerCli\Panel\UserRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PanelPasswordRotateCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('panel:password-rotate');
        $this->setDescription('Сгенерировать новые пароли пользователям панели.');
        $this->addArgument('users', InputArgument::REQUIRED, 'Логины пользователей через запятую.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $value = $input->getArgument('users');
        $logins = array_values(array_filter(array_map('trim', explode(',', is_string($value) ? $value : ''))));
        try {
            $logins = array_map(UserRepository::normalizeLogin(...), $logins);
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        }
        if ($logins === []) {
            $output->writeln('<error>Укажите хотя бы одного пользователя.</error>');
            return Command::INVALID;
        }
        $salt = (new SystemCompose())->envValue('PANEL_PASSWORD_SALT');
        if ($salt === '') {
            $output->writeln('<error>Соль паролей не настроена. Выполните `docker-cli config:init`.</error>');
            return Command::FAILURE;
        }
        $users = new UserRepository($salt);
        $tokens = new TokenRepository();
        $generator = new PanelPasswordGenerator();
        $failed = false;
        foreach (array_unique($logins) as $login) {
            $password = $generator->generate();
            if (!$users->rotatePassword($login, $password)) {
                $output->writeln(sprintf('<error>Пользователь %s не существует.</error>', $login));
                $failed = true;
                continue;
            }
            $revoked = $tokens->revoke([$login]);
            $output->writeln(sprintf('<info>%s: %s (отозвано токенов: %d)</info>', $login, $password, $revoked));
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
