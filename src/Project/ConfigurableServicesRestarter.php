<?php

declare(strict_types=1);

namespace DockerCli\Project;

use DockerCli\Config\MissingConfigException;
use DockerCli\Config\SystemCompose;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

final class ConfigurableServicesRestarter
{
    /** @var list<string> */
    private const SERVICES = ['traefik', 'openresty', 'php-fpm-8.2'];
    private const DNS_SERVICE = 'dnsdock';

    public function restart(OutputInterface $output): int
    {
        $compose = new SystemCompose();
        try {
            $compose->assertInitialized();
        } catch (MissingConfigException $exception) {
            $output->writeln(sprintf('<error>Не найдены необходимые файлы настроек docker-cli: %s.</error>', implode(', ', $exception->missingFiles())));

            return Command::FAILURE;
        }

        $restartCode = $this->run(
            array_merge($compose->dockerComposeCommand('up'), ['--detach', '--force-recreate'], self::SERVICES),
            $compose,
            $output,
        );
        if ($restartCode !== Command::SUCCESS) {
            return $restartCode;
        }

        // Dnsdock can retain the address of the replaced Traefik container when
        // several Docker events arrive during the first config rebuild. Reload
        // its state after all configurable services have received their final
        // addresses so project hosts do not resolve to the removed container.
        return $this->run(
            array_merge($compose->dockerComposeCommand('restart'), [self::DNS_SERVICE]),
            $compose,
            $output,
        );
    }

    /** @param list<string> $command */
    private function run(array $command, SystemCompose $compose, OutputInterface $output): int
    {
        $output->writeln('<comment>Выполняется: ' . implode(' ', array_map('escapeshellarg', $command)) . '</comment>');

        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, $compose->dockerProcessEnvironment());
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start docker compose process.');
        }

        return proc_close($process);
    }
}
