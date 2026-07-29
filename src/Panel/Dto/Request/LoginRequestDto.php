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
        $login = $request->body['login'] ?? null;
        $password = $request->body['password'] ?? null;
        if (!is_string($login) || strlen($login) > 254
            || !is_string($password) || strlen($password) > 1024) {
            throw new RequestValidationException('Некорректный запрос.');
        }

        return new static($login, $password);
    }
}
