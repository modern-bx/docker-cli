<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

/** Internal response DTO describing a static panel file. */
final readonly class FileResponseDto
{
    public function __construct(public string $relativePath)
    {
    }
}
