<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class LogoutResponseDto implements \JsonSerializable
{
    /** @return array{success: true} */
    public function jsonSerialize(): array
    {
        return ['success' => true];
    }
}
