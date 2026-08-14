<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class ProjectsSettingsDto implements \JsonSerializable
{
    /** @param list<array{path: string, code: string, default: bool}> $locations */
    /** @param list<array{path: string, code: string, default: bool}> $databaseLocations */
    public function __construct(public array $locations, public array $databaseLocations)
    {
    }

    /** @return array{locations: list<array{path: string, code: string, default: bool}>, databaseLocations: list<array{path: string, code: string, default: bool}>} */
    public function jsonSerialize(): array
    {
        return ['locations' => $this->locations, 'databaseLocations' => $this->databaseLocations];
    }
}
