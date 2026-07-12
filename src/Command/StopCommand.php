<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\SystemCompose;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'stop', description: 'Stop system services and remove the shared docker-cli network.')]
final class StopCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $compose = new SystemCompose();
        $compose->ensure();

        $output->writeln(sprintf('<info>Using system compose file:</info> %s', $compose->composeFile()));

        return $this->runDockerCompose($compose->dockerComposeCommand('down'), ['--remove-orphans'], $output);
    }

    /** @param list<string> $command @param list<string> $arguments */
    private function runDockerCompose(array $command, array $arguments, OutputInterface $output): int
    {
        $fullCommand = array_merge($command, $arguments);
        $output->writeln('<comment>Running:</comment> ' . implode(' ', array_map('escapeshellarg', $fullCommand)));

        $process = proc_open($fullCommand, [STDIN, STDOUT, STDERR], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start docker compose process.');
        }

        return proc_close($process);
    }
}
