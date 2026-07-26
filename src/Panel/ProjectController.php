<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use DockerCli\Panel\Dto\ProjectDto;
use DockerCli\Panel\Dto\ProjectListDto;
use DockerCli\Project\ProjectRegistry;

final class ProjectController
{
    public function __construct(private readonly ProjectRegistry $projects)
    {
    }

    public function index(): ProjectListDto
    {
        $projects = [];
        foreach ($this->projects->registeredProjectNames() as $name) {
            $config = $this->projects->readProjectConfig($name);
            $project = is_array($config['data']['project'] ?? null) ? $config['data']['project'] : [];
            $projects[] = new ProjectDto(
                name: is_string($project['name'] ?? null) && $project['name'] !== '' ? $project['name'] : $name,
                language: $this->nullableString($project['language'] ?? null),
                framework: $this->nullableString($project['framework'] ?? null),
                // Older project configs predate this flag and are enabled by default,
                // just like OpenRestyHostRenderer treats them.
                enabled: ($project['enabled'] ?? true) !== false,
            );
        }

        return new ProjectListDto($projects);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
