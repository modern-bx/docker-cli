<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use DockerCli\Service\SystemdUnitPolicy;

final class SystemdService
{
    public const NAME = 'docker-cli.panel';
    public const UNIT_PATH = '/etc/systemd/system/' . self::NAME . '.service';

    public function __construct(private readonly SystemdUnitPolicy $policy = new SystemdUnitPolicy())
    {
    }

    public function install(string $binary, ?int $port = null, ?string $user = null): void
    {
        $previousUnit = is_file(self::UNIT_PATH) ? file_get_contents(self::UNIT_PATH) : false;
        $previousPolicy = $this->policy->contents(self::NAME . '.service');
        $arguments = [$binary, 'panel:up'];
        if ($port !== null) {
            $arguments[] = '--port=' . $port;
        }

        $service = [
            '[Service]',
            'Type=simple',
        ];
        if ($user !== null) {
            $service[] = 'User=' . $user;
        }
        $service[] = 'ExecStart=' . implode(' ', array_map($this->escapeArgument(...), $arguments));
        $service[] = 'Restart=on-failure';

        $unit = implode("\n", [
            '[Unit]',
            'Description=Docker CLI administrative panel',
            'After=network.target docker.service',
            '',
            ...$service,
            '',
            '[Install]',
            'WantedBy=multi-user.target',
            '',
        ]);

        if (@file_put_contents(self::UNIT_PATH, $unit, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Не удалось записать конфигурацию сервиса в %s.', self::UNIT_PATH));
        }

        try {
            $this->policy->install(self::NAME . '.service', $user);
            $this->systemctl('daemon-reload');
            $this->systemctl('enable', self::NAME . '.service');
            $this->systemctl('restart', self::NAME . '.service');
        } catch (\Throwable $exception) {
            if ($previousUnit === false) {
                @unlink(self::UNIT_PATH);
            } else {
                @file_put_contents(self::UNIT_PATH, $previousUnit, LOCK_EX);
            }
            $this->policy->restore(self::NAME . '.service', $previousPolicy);
            $this->runSystemctl('daemon-reload');
            throw $exception;
        }
    }

    public function remove(): void
    {
        if (!is_file(self::UNIT_PATH)) {
            throw new \RuntimeException(sprintf('Сервис %s не установлен.', self::NAME));
        }

        $this->systemctl('disable', '--now', self::NAME . '.service');
        if (!@unlink(self::UNIT_PATH)) {
            throw new \RuntimeException(sprintf('Не удалось удалить конфигурацию сервиса %s.', self::UNIT_PATH));
        }
        $this->policy->remove(self::NAME . '.service');
        $this->systemctl('daemon-reload');
        $this->runSystemctl('reset-failed', self::NAME . '.service');
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
}
