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
                $services[$driver][$compose->databaseService($projectName, $driver)] = $this->service($driver, $projectName, $hostname);
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
    private function service(string $driver, string $projectName, string $hostname): array
    {
        $dataDirectory = sprintf('./data/%s-%s', $driver, $projectName);
        if ($driver === 'mysql') {
            return [
                'image' => 'mysql:8.0',
                'container_name' => $hostname,
                'environment' => [
                    'MYSQL_ROOT_PASSWORD' => '${MYSQL_ROOT_PASSWORD}',
                    'MYSQL_DATABASE' => '${MYSQL_DATABASE:-system}',
                    'MYSQL_USER' => '${MYSQL_USER:-system}',
                    'MYSQL_PASSWORD' => '${MYSQL_PASSWORD}',
                ],
                'volumes' => [$dataDirectory . '/data:/var/lib/mysql', $dataDirectory . '/logs:/var/log/mysql'],
                'networks' => ['docker-cli' => ['aliases' => [$hostname]]],
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
            ],
            'volumes' => [$dataDirectory . '/data:/var/lib/postgresql', $dataDirectory . '/logs:/var/log/postgresql'],
            'networks' => ['docker-cli' => ['aliases' => [$hostname]]],
            'restart' => 'unless-stopped',
        ];
    }
}
