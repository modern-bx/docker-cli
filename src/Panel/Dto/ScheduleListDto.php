<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class ScheduleListDto implements \JsonSerializable
{
    /** @param list<array{schedule: string, command: string, workingDirectory: string}> $items */
    public function __construct(public array $items)
    {
    }

    public function jsonSerialize(): array
    {
        return ['items' => $this->items];
    }
}
