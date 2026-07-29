<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;
use DockerCli\Panel\SecuritySettingsRepository;

final readonly class SecuritySettingsRequestDto implements RequestDto
{
    public function __construct(public int $maximumSessionHours)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $hours = $request->body['maximumSessionHours'] ?? null;
        if (!is_int($hours) || $hours < 1 || $hours > SecuritySettingsRepository::MAX_SESSION_HOURS) {
            throw new RequestValidationException(sprintf('Длительность сессии должна быть от 1 до %d часов.', SecuritySettingsRepository::MAX_SESSION_HOURS));
        }
        return new static($hours);
    }
}
