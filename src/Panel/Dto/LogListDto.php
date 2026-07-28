<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class LogListDto implements \JsonSerializable
{
    /** @param list<array<string, mixed>> $items @param list<string> $projects */
    public function __construct(public array $items, public int $total, public array $projects)
    {
    }

    /** @return array{items: list<array<string, mixed>>, total: int, projects: list<string>} */
    public function jsonSerialize(): array
    {
        return ['items' => $this->items, 'total' => $this->total, 'projects' => $this->projects];
    }
}
