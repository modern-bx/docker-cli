<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;
use DockerCli\Panel\UserRepository;

final readonly class UsersSettingsRequestDto implements RequestDto
{
    public function __construct(public string $login, public string $comments, public string $currentLogin)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        try {
            $login = UserRepository::normalizeLogin((string) ($request->route['login'] ?? $request->body['login'] ?? ''));
        } catch (\InvalidArgumentException $exception) {
            throw new RequestValidationException($exception->getMessage());
        }
        $comments = $request->body['comments'] ?? '';
        if (!is_string($comments) || strlen($comments) > 10000) {
            throw new RequestValidationException('Комментарии не должны превышать 10000 байт.');
        }
        return new static($login, $comments, $request->login ?? '');
    }
}
