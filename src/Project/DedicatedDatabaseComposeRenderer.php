<?php

declare(strict_types=1);

namespace DockerCli\Project;

use DockerCli\Config\SystemCompose;
use Symfony\Component\Yaml\Yaml;

/** Builds compose fragments for database servers owned by registered projects. */
final class DedicatedDatabaseComposeRenderer
{
    public function __construct(
        private readonly ?ProjectRegistry $registry = null,
        private readonly ?SystemCompose $compose = null,
    ) {}

    public function render(): void
    {
        $registry = $this->registry ?? new ProjectRegistry();
        $compose = $this->compose ?? new SystemCompose();
        $services = ['mysql' => [], 'postgres' => []];
        foreach ($registry->registeredProjectNames() as $projectName) {
            $databases = $registry->readProjectConfig($projectName)['data']['databases'] ?? [];
            foreach (array_keys($services) as $driver) {
                $hostname = $databases[$driver]['hostname'] ?? null;
                $expected = sprintf('docker-cli-%s-%s', $driver, $projectName);
                if ($hostname !== $expected) {
                    continue;
                }
                $location = $databases[$driver]['location'] ?? null;
                $services[$driver][$compose->databaseService($projectName, $driver)] = $this->service(
                    $driver,
                    $projectName,
                    $hostname,
                    is_string($location) && $location !== '' ? $location : null,
                );
            }
        }

        foreach ($services as $driver => $definitions) {
            $file = $compose->additionalComposeFile($driver);
            if ($definitions === []) {
                if (is_file($file)) {
                    unlink($file);
                }
                continue;
            }
            file_put_contents($file, Yaml::dump(['services' => $definitions], 8, 2));
        }
    }

    /** @return array<string, mixed> */
    private function service(string $driver, string $projectName, string $hostname, ?string $location): array
    {
        $dataDirectory = $location ?? sprintf('${DEFAULT_DATA_DIR_%s:-data/%s}-%s', strtoupper($driver), $driver, $projectName);
        if ($driver === 'mysql') {
            return [
                'image' => 'mysql:8.0',
                'container_name' => $hostname,
                'environment' => [
                    'MYSQL_ROOT_PASSWORD' => '${MYSQL_ROOT_PASSWORD}',
                    'MYSQL_DATABASE' => '${MYSQL_DATABASE:-system}',
                    'MYSQL_USER' => '${MYSQL_USER:-system}',
                    'MYSQL_PASSWORD' => '${MYSQL_PASSWORD}',
                    'DNSDOCK_NAME' => $hostname,
                    'DNSDOCK_IMAGE' => 'system',
                ],
                'volumes' => [
                    ['type' => 'bind', 'source' => $dataDirectory . '/data', 'target' => '/var/lib/mysql'],
                    ['type' => 'bind', 'source' => $dataDirectory . '/logs', 'target' => '/var/log/mysql'],
                ],
                'networks' => ['docker-cli' => ['aliases' => [$hostname]]],
                'healthcheck' => [
                    'test' => ['CMD-SHELL', 'MYSQL_PWD="$${MYSQL_ROOT_PASSWORD}" mysqladmin --protocol=socket -uroot ping --silent'],
                    'interval' => '2s',
                    'timeout' => '2s',
                    'retries' => 60,
                ],
                'restart' => 'unless-stopped',
            ];
        }

        return [
            'image' => 'postgres:18',
            'container_name' => $hostname,
            'environment' => [
                'POSTGRES_DB' => '${POSTGRES_DB:-system}',
                'POSTGRES_USER' => '${POSTGRES_USER:-system}',
                'POSTGRES_PASSWORD' => '${POSTGRES_PASSWORD}',
                'DNSDOCK_NAME' => $hostname,
                'DNSDOCK_IMAGE' => 'system',
            ],
            'volumes' => [
                ['type' => 'bind', 'source' => $dataDirectory . '/data', 'target' => '/var/lib/postgresql'],
                ['type' => 'bind', 'source' => $dataDirectory . '/logs', 'target' => '/var/log/postgresql'],
            ],
            'networks' => ['docker-cli' => ['aliases' => [$hostname]]],
            'healthcheck' => [
                'test' => ['CMD-SHELL', 'pg_isready -U "$${POSTGRES_USER:-system}" -d postgres'],
                'interval' => '2s',
                'timeout' => '2s',
                'retries' => 60,
            ],
            'restart' => 'unless-stopped',
        ];
    }
}
