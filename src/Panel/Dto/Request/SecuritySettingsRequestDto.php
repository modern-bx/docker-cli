<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;
use DockerCli\Panel\SecuritySettingsRepository;

final readonly class SecuritySettingsRequestDto implements RequestDto
{
    public function __construct(public int $maximumSessionHours, public string $httpAuthLogin, public string $httpAuthPassword)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $hours = $request->body['maximumSessionHours'] ?? null;
        if (!is_int($hours) || $hours < 1 || $hours > SecuritySettingsRepository::MAX_SESSION_HOURS) {
            throw new RequestValidationException(sprintf('Длительность сессии должна быть от 1 до %d часов.', SecuritySettingsRepository::MAX_SESSION_HOURS));
        }
        foreach (['httpAuthLogin', 'httpAuthPassword'] as $key) {
            if (isset($request->body[$key]) && !is_string($request->body[$key])) {
                throw new RequestValidationException('HTTP-авторизация должна быть строкой.');
            }
        }
        $login = trim((string) ($request->body['httpAuthLogin'] ?? ''));
        $password = (string) ($request->body['httpAuthPassword'] ?? '');
        if (str_contains($login, ':') || str_contains($login, "\n") || str_contains($login, "\r")) {
            throw new RequestValidationException('Логин HTTP-авторизации не должен содержать двоеточие или переносы строк.');
        }
        if (str_contains($password, "\n") || str_contains($password, "\r")) {
            throw new RequestValidationException('Пароль HTTP-авторизации не должен содержать переносы строк.');
        }
        return new static($hours, $login, $password);
    }
}
