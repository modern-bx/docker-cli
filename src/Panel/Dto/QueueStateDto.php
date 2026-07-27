<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class QueueStateDto implements \JsonSerializable
{
    /** @param list<QueueItemDto> $items */
    public function __construct(public string $name, public array $items)
    {
    }

    /** @return array{name: string, items: list<QueueItemDto>} */
    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'items' => $this->items];
    }
}
