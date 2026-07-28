<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\SystemCompose;
use DockerCli\Panel\UserRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

final class PanelUserCreateCommand extends Command
{
    public function __construct()
    {
        parent::__construct('panel:user-create');
        $this->setDescription('Создать пользователя административной панели.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $compose = new SystemCompose();
        $salt = $compose->envValue('PANEL_PASSWORD_SALT');
        if ($salt === '') {
            $output->writeln('<error>Соль паролей не настроена. Выполните `docker-cli config:init`.</error>');
            return Command::FAILURE;
        }

        $loginQuestion = new Question('Логин (email): ');
        $loginQuestion->setValidator(static fn (mixed $answer): string => UserRepository::normalizeLogin(is_string($answer) ? $answer : ''));
        $passwordQuestion = new Question('Пароль: ');
        $passwordQuestion->setHidden(true);
        $passwordQuestion->setValidator(static function (mixed $answer): string {
            if (!is_string($answer) || $answer === '') {
                throw new \InvalidArgumentException('Пароль не должен быть пустым.');
            }
            return $answer;
        });

        $helper = $this->getHelper('question');
        $login = $helper->ask($input, $output, $loginQuestion);
        $password = $helper->ask($input, $output, $passwordQuestion);
        $repository = new UserRepository($salt);
        if (!$repository->add($login, $password)) {
            $output->writeln(sprintf('<error>Пользователь %s уже существует.</error>', $login));
            return Command::FAILURE;
        }
        $output->writeln(sprintf('<info>Пользователь %s создан.</info>', $login));
        return Command::SUCCESS;
    }
}
