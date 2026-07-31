<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ShellBashCommand extends AbstractShellCommand
{
    public function __construct(?ProjectRegistry $registry = null)
    {
        parent::__construct('shell:bash', $registry);
        $this->setAliases(['bash']);
        $this->setDescription('Открыть Bash в контейнере PHP-FPM от имени пользователя docker-cli.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runInPhpFpm($input, $output, [
            'bash',
            '--noprofile',
            '--rcfile', '/home/docker-cli/.docker-cli.profile',
        ]);
    }
}
