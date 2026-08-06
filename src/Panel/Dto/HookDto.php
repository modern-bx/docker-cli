<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class HookDto implements \JsonSerializable
{
    /** @param array{id: string, level: string, command: string, timing: string, enabled: bool, hook: string} $hook */
    public function __construct(public array $hook)
    {
    }

    public function jsonSerialize(): array
    {
        return ['hook' => $this->hook];
    }
}
