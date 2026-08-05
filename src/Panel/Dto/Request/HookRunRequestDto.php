<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class HookRunRequestDto implements RequestDto
{
    public function __construct(public string $id, public string $profile, public string $workingDirectory)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $action = HookActionRequestDto::fromRequest($request);
        $profile = $request->body['profile'] ?? null;
        $workingDirectory = $request->body['workingDirectory'] ?? '';
        if (!is_string($profile) || trim($profile) === '') {
            throw new RequestValidationException('Некорректный профиль запуска хука.');
        }
        if (!is_string($workingDirectory) || str_contains($workingDirectory, "\0")) {
            throw new RequestValidationException('Некорректная рабочая директория хука.');
        }

        return new static($action->id, $profile, trim($workingDirectory));
    }
}
