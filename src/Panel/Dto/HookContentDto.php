<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class HookContentDto implements \JsonSerializable
{
    public function __construct(public string $content)
    {
    }

    /** @return array{content: string} */
    public function jsonSerialize(): array
    {
        return ['content' => $this->content];
    }
}
