<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

/** Error response shared by all panel API routes. */
final readonly class ErrorResponseDto implements \JsonSerializable
{
    public function __construct(public string $error)
    {
    }

    /** @return array{error: string} */
    public function jsonSerialize(): array
    {
        return ['error' => $this->error];
    }
}
