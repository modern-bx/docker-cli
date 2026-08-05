<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class HookListDto implements \JsonSerializable
{
    /** @param list<array{id: string, level: string, command: string, timing: string, enabled: bool, hook: string}> $hooks */
    public function __construct(public array $hooks)
    {
    }

    /** @return array{hooks: list<array{id: string, level: string, command: string, timing: string, enabled: bool, hook: string}>} */
    public function jsonSerialize(): array
    {
        return ['hooks' => $this->hooks];
    }
}
