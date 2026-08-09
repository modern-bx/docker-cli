<?php

declare(strict_types=1);

namespace DockerCli\Queue;

use DockerCli\Service\SystemdUnitPolicy;

final class SystemdService
{
    private const UNIT_DIRECTORY = '/etc/systemd/system';

    public function __construct(private readonly SystemdUnitPolicy $policy = new SystemdUnitPolicy())
    {
    }

    public function name(string $queue): string
    {
        return 'docker-cli.queue.' . $queue;
    }

    public function unitPath(string $queue): string
    {
        return self::UNIT_DIRECTORY . '/' . $this->name($queue) . '.service';
    }

    public function install(string $queue, string $binary, ?string $user = null): void
    {
        $name = $this->name($queue);
        $unitPath = $this->unitPath($queue);
        $previousUnit = is_file($unitPath) ? file_get_contents($unitPath) : false;
        $previousPolicy = $this->policy->contents($name . '.service');
        $service = ['[Service]', 'Type=simple'];
        if ($user !== null) {
            $service[] = 'User=' . $user;
        }
        $service[] = 'Environment=' . $this->escapeArgument('PATH=' . $this->servicePath($binary));
        $service[] = 'ExecStart=' . implode(' ', array_map($this->escapeArgument(...), [
            $binary,
            'queue:start',
            '--queue=' . $queue,
        ]));
        $service[] = 'Restart=on-failure';

        $unit = implode("\n", [
            '[Unit]',
            sprintf('Description=Docker CLI queue worker (%s)', $queue),
            'After=network.target docker.service',
            '',
            ...$service,
            '',
            '[Install]',
            'WantedBy=multi-user.target',
            '',
        ]);

        if (@file_put_contents($unitPath, $unit, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Не удалось записать конфигурацию сервиса в %s.', $unitPath));
        }

        try {
            $this->policy->install($name . '.service', $user);
            $this->systemctl('daemon-reload');
            $this->systemctl('enable', $name . '.service');
            $this->systemctl('restart', $name . '.service');
        } catch (\Throwable $exception) {
            if ($previousUnit === false) {
                @unlink($unitPath);
            } else {
                @file_put_contents($unitPath, $previousUnit, LOCK_EX);
            }
            $this->policy->restore($name . '.service', $previousPolicy);
            $this->runSystemctl('daemon-reload');
            throw $exception;
        }
    }

    public function remove(string $queue): void
    {
        $name = $this->name($queue);
        $unitPath = $this->unitPath($queue);
        if (!is_file($unitPath)) {
            throw new \RuntimeException(sprintf('Сервис %s не установлен.', $name));
        }

        $this->systemctl('disable', '--now', $name . '.service');
        if (!@unlink($unitPath)) {
            throw new \RuntimeException(sprintf('Не удалось удалить конфигурацию сервиса %s.', $unitPath));
        }
        $this->policy->remove($name . '.service');
        $this->systemctl('daemon-reload');
        $this->runSystemctl('reset-failed', $name . '.service');
    }

    /** @return array{0: int, 1: string} */
    private function runSystemctl(string ...$arguments): array
    {
        $pipes = [];
        $process = proc_open(['systemctl', ...$arguments], [
            ['file', '/dev/null', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            return [1, 'не удалось запустить systemctl'];
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), trim($stderr !== '' ? $stderr : $stdout)];
    }

    private function systemctl(string ...$arguments): void
    {
        [$status, $message] = $this->runSystemctl(...$arguments);
        if ($status !== 0) {
            throw new \RuntimeException(sprintf('systemctl %s завершился с ошибкой: %s', implode(' ', $arguments), $message));
        }
    }

    private function escapeArgument(string $argument): string
    {
        return '"' . str_replace(['\\', '"', '%'], ['\\\\', '\\"', '%%'], $argument) . '"';
    }

    private function servicePath(string $binary): string
    {
        $path = getenv('PATH');
        $directories = is_string($path) ? explode(PATH_SEPARATOR, $path) : [];
        array_unshift($directories, dirname($binary));

        return implode(PATH_SEPARATOR, array_values(array_unique(array_filter(
            $directories,
            static fn (string $directory): bool => $directory !== '',
        ))));
    }
}
