<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class SecuritySettingsDto implements \JsonSerializable
{
    public function __construct(public int $maximumSessionHours)
    {
    }

    /** @return array{maximumSessionHours: int} */
    public function jsonSerialize(): array
    {
        return ['maximumSessionHours' => $this->maximumSessionHours];
    }
}
