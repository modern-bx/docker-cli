<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ShellRunCommand extends AbstractShellCommand
{
    public function __construct(?ProjectRegistry $registry = null)
    {
        parent::__construct('shell:run', $registry);
        $this->setAliases(['run']);
        $this->setDescription('Выполнить команду в контейнере PHP-FPM от имени пользователя docker-cli.');
        $this->addArgument('args', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Команда и её аргументы.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $arguments */
        $arguments = $input->getArgument('args');

        return $this->runInPhpFpm($input, $output, ['bash', '-c', implode(' ', $arguments)]);
    }
}
