<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use DockerCli\Config\SystemCompose;
use DockerCli\Panel\Dto\SystemServiceDto;
use DockerCli\Panel\Dto\SystemStatusDto;

final class SystemController
{
    public function __construct(private readonly SystemCompose $compose)
    {
    }

    public function status(): SystemStatusDto
    {
        $configured = $this->configuredServices();
        $running = $this->runningServices();
        $services = [];
        foreach ($configured as $name => $image) {
            $services[] = new SystemServiceDto($name, $image, isset($running[$name]));
        }

        $runningCount = count(array_filter($services, static fn (SystemServiceDto $service): bool => $service->running));
        $status = $runningCount === 0 ? 'stopped' : ($runningCount === count($services) ? 'running' : 'partial');

        return new SystemStatusDto($status, $services);
    }

    public function act(string $action, ?string $service = null): SystemStatusDto
    {
        $arguments = match ($action) {
            'start' => ['up', '-d'],
            'stop' => ['stop'],
            'restart' => ['restart'],
            default => throw new SystemActionException('Неизвестное действие.', 400),
        };
        if ($service !== null) {
            if (!array_key_exists($service, $this->configuredServices())) {
                throw new SystemActionException('Сервис не найден.', 404);
            }
            $arguments[] = $service;
        }

        $this->run($arguments);

        return $this->status();
    }

    /** @return array<string, string> */
    private function configuredServices(): array
    {
        [$code, $output] = $this->run(['config', '--format', 'json'], false);
        $config = json_decode($output, true);
        if ($code !== 0 || !is_array($config)) {
            throw new SystemActionException($output !== '' ? $output : 'Не удалось прочитать конфигурацию Docker Compose.');
        }
        // `docker compose config` performs the same .env interpolation that is
        // used to start containers, so image tooltips never expose raw ${...}.
        $definitions = is_array($config['services'] ?? null) ? $config['services'] : [];
        $services = [];
        foreach ($definitions as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                continue;
            }
            // Profile-only tools and one-shot initialization jobs are not
            // continuously running system components.
            if (isset($definition['profiles']) || ($definition['restart'] ?? null) === 'no') {
                continue;
            }
            $image = $definition['image'] ?? $name;
            $services[$name] = is_string($image) ? $image : $name;
        }
        ksort($services, SORT_STRING);

        return $services;
    }

    /** @return array<string, true> */
    private function runningServices(): array
    {
        [$code, $output] = $this->run(['ps', '--all', '--format', 'json'], false);
        if ($code !== 0) {
            throw new SystemActionException($output !== '' ? $output : 'Не удалось получить состояние системы.');
        }
        $rows = [];
        $decoded = json_decode($output, true);
        if (is_array($decoded) && array_is_list($decoded)) {
            $rows = $decoded;
        } else {
            foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
                $row = json_decode($line, true);
                if (is_array($row)) $rows[] = $row;
            }
        }
        $running = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['Service'] ?? null) && strtolower((string) ($row['State'] ?? '')) === 'running') {
                $running[$row['Service']] = true;
            }
        }

        return $running;
    }

    /** @param list<string> $arguments @return array{int, string} */
    private function run(array $arguments, bool $fail = true): array
    {
        $command = [...array_slice($this->compose->dockerComposeCommand(''), 0, -1), ...$arguments];
        $process = proc_open($command, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $this->compose->directory(), $this->compose->dockerProcessEnvironment());
        if (!is_resource($process)) throw new SystemActionException('Не удалось запустить Docker Compose.');
        $stdout = trim(stream_get_contents($pipes[1]));
        $stderr = trim(stream_get_contents($pipes[2]));
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process);
        // Compose warnings go to stderr even on success and must not corrupt
        // machine-readable JSON requested on stdout.
        $output = $code === 0 ? $stdout : trim($stdout . "\n" . $stderr);
        if ($fail && $code !== 0) throw new SystemActionException($output !== '' ? $output : 'Docker Compose завершился с ошибкой.');

        return [$code, $output];
    }
}
