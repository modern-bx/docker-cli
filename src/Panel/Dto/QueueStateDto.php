<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class QueueStateDto implements \JsonSerializable
{
    /** @param list<QueueItemDto> $items */
    public function __construct(public string $name, public bool $paused, public array $items)
    {
    }

    /** @return array{name: string, paused: bool, items: list<QueueItemDto>} */
    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'paused' => $this->paused, 'items' => $this->items];
    }
}
