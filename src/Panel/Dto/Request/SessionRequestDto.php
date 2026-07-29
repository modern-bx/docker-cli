<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

/** Authenticated request carrying the login resolved by middleware. */
final readonly class SessionRequestDto implements RequestDto
{
    public function __construct(public string $login, public int $sessionStartedAt)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        if ($request->login === null || $request->sessionStartedAt === null) {
            throw new RequestValidationException('Сессия истекла.');
        }

        return new static($request->login, $request->sessionStartedAt);
    }
}
