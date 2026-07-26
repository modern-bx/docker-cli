<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

/** DTO returned by GET /api/system. */
final readonly class SystemStatusDto implements \JsonSerializable
{
    /** @param list<SystemServiceDto> $services */
    public function __construct(public string $status, public array $services)
    {
    }

    /** @return array{status: 'running'|'partial'|'stopped', services: list<SystemServiceDto>} */
    public function jsonSerialize(): array
    {
        return ['status' => $this->status, 'services' => $this->services];
    }
}
