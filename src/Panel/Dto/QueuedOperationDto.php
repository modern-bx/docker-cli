<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class QueuedOperationDto implements \JsonSerializable
{
    public function __construct(public string $file)
    {
    }

    /** @return array{file: string} */
    public function jsonSerialize(): array
    {
        return ['file' => $this->file];
    }
}
