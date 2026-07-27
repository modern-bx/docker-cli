<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

/** JSON request accepted by POST /api/auth/login. */
final readonly class LoginRequestDto implements RequestDto
{
    public function __construct(public string $login, public string $password)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        if (!is_string($request->body['login'] ?? null) || !is_string($request->body['password'] ?? null)) {
            throw new RequestValidationException('Некорректный запрос.');
        }

        return new static($request->body['login'], $request->body['password']);
    }
}
