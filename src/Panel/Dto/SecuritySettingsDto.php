<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class SecuritySettingsDto implements \JsonSerializable
{
    public function __construct(public int $maximumSessionHours, public string $httpAuthLogin = '', public string $httpAuthPassword = '', public ?string $queuedOperation = null)
    {
    }

    /** @return array{maximumSessionHours: int, httpAuthLogin: string, httpAuthPassword: string, queuedOperation: string|null} */
    public function jsonSerialize(): array
    {
        return [
            'maximumSessionHours' => $this->maximumSessionHours,
            'httpAuthLogin' => $this->httpAuthLogin,
            'httpAuthPassword' => $this->httpAuthPassword,
            'queuedOperation' => $this->queuedOperation,
        ];
    }
}
