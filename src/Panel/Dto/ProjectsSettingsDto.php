<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class ProjectsSettingsDto implements \JsonSerializable
{
    /** @param list<array{path: string, code: string, default: bool}> $locations */
    public function __construct(public array $locations)
    {
    }

    /** @return array{locations: list<array{path: string, code: string, default: bool}>} */
    public function jsonSerialize(): array
    {
        return ['locations' => $this->locations];
    }
}
