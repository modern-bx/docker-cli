<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

/** DTO returned by GET /api/projects. */
final readonly class ProjectListDto implements \JsonSerializable
{
    /** @param list<ProjectDto> $projects */
    public function __construct(public array $projects)
    {
    }

    /** @return array{projects: list<ProjectDto>} */
    public function jsonSerialize(): array
    {
        return ['projects' => $this->projects];
    }
}
