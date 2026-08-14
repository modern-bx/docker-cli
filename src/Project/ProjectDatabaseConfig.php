<?php

declare(strict_types=1);

namespace DockerCli\Project;

final class ProjectDatabaseConfig
{
    /** @return array<string, mixed> */
    public function ensure(array $projectConfig, array $dedicated = [], array $locations = []): array
    {
        $projectName = $projectConfig['data']['project']['name'] ?? null;
        if (!is_string($projectName) || $projectName === '') {
            throw new \RuntimeException('Project name is missing in project config.');
        }

        $generator = new DatabasePasswordGenerator();
        foreach (['mysql', 'postgres'] as $driver) {
            $password = $projectConfig['data']['databases'][$driver]['password'] ?? null;
            $hostname = $projectConfig['data']['databases'][$driver]['hostname'] ?? null;
            $location = $projectConfig['data']['databases'][$driver]['location'] ?? ($locations[$driver] ?? null);
            $projectConfig['data']['databases'][$driver] = [
                'database' => $projectName,
                'username' => $projectName,
                'password' => is_string($password) && $password !== '' ? $password : $generator->generate(),
                'hostname' => is_string($hostname) && $hostname !== ''
                    ? $hostname
                    : (in_array($driver, $dedicated, true) ? sprintf('docker-cli-%s-%s', $driver, $projectName) : 'docker-cli-' . $driver),
            ];
            if (is_string($location) && $location !== '') {
                $projectConfig['data']['databases'][$driver]['location'] = $location;
            }
        }

        if ($projectConfig['data']['databases']['mysql']['password'] === $projectConfig['data']['databases']['postgres']['password']) {
            do {
                $projectConfig['data']['databases']['postgres']['password'] = $generator->generate();
            } while ($projectConfig['data']['databases']['mysql']['password'] === $projectConfig['data']['databases']['postgres']['password']);
        }

        return $projectConfig;
    }
}
