<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\System\SudoersManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SystemRollbackCommand extends SystemUserCommand {
    public function __construct(private readonly ?SudoersManager $sudoers = null) {
        parent::__construct("system:rollback");
        $this->setDescription("Откатить системные права, настроенные для docker-cli.");
        $this->addOption(
            "user",
            null,
            InputOption::VALUE_REQUIRED,
            "Логин пользователя; по умолчанию " . "пользователь, вызвавший sudo.",
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        try {
            $user = $this->resolveUser($input);
            $file = ($this->sudoers ?? new SudoersManager())->rollback($user);
        } catch (\RuntimeException $exception) {
            $this->writeMessage($output, "<error>" . $exception->getMessage() . "</error>");
            return Command::FAILURE;
        }
        $this->writeMessage(
            $output,
            sprintf('<info>Конфигурация sudo для пользователя "%s" ' . 'удалена: "%s".</info>', $user, $file),
        );

        return Command::SUCCESS;
    }
}
