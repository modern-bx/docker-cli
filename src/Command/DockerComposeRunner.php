<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Config\SystemCompose;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

trait DockerComposeRunner
{
    private function runOperation(SystemCompose $compose, string $operation, array $arguments, OutputInterface $output, TranslatorInterface $translator): int
    {
        try {
            $compose->assertInitialized();
        } catch (MissingConfigException $exception) {
            $output->writeln('<error>' . $translator->trans('config.missing', [
                '%files%' => implode(', ', $exception->missingFiles()),
                '%directory%' => $exception->configDirectory(),
            ]) . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>' . $translator->trans('config.using', ['%file%' => $compose->composeFile()]) . '</info>');

        $fullCommand = array_merge($compose->dockerComposeCommand($operation), $arguments);
        $output->writeln('<comment>' . $translator->trans('process.running', [
            '%command%' => implode(' ', array_map('escapeshellarg', $fullCommand)),
        ]) . '</comment>');

        $process = proc_open($fullCommand, [STDIN, STDOUT, STDERR], $pipes, null, $compose->dockerProcessEnvironment());
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start docker compose process.');
        }

        return proc_close($process);
    }
}
