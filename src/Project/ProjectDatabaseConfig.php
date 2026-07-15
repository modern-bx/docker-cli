<?php

declare(strict_types=1);

namespace DockerCli\Project;

final class ProjectDatabaseConfig
{
    /** @return array<string, mixed> */
    public function ensure(array $projectConfig): array
    {
        $projectName = $projectConfig['data']['project']['name'] ?? null;
        if (!is_string($projectName) || $projectName === '') {
            throw new \RuntimeException('Project name is missing in project config.');
        }

        $generator = new DatabasePasswordGenerator();
        foreach (['mysql', 'postgres'] as $driver) {
            $password = $projectConfig['data']['databases'][$driver]['password'] ?? null;
            $projectConfig['data']['databases'][$driver] = [
                'database' => $projectName,
                'username' => $projectName,
                'password' => is_string($password) && $password !== '' ? $password : $generator->generate(),
            ];
        }

        if ($projectConfig['data']['databases']['mysql']['password'] === $projectConfig['data']['databases']['postgres']['password']) {
            do {
                $projectConfig['data']['databases']['postgres']['password'] = $generator->generate();
            } while ($projectConfig['data']['databases']['mysql']['password'] === $projectConfig['data']['databases']['postgres']['password']);
        }

        return $projectConfig;
    }
}
