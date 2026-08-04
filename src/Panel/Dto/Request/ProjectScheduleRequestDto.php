<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class ProjectScheduleRequestDto implements RequestDto
{
    public function __construct(public string $name, public string $schedule, public string $command, public string $workingDirectory)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        foreach (['schedule', 'command'] as $field) {
            if (!is_string($request->body[$field] ?? null) || trim($request->body[$field]) === '') {
                throw new RequestValidationException('Расписание и команда обязательны.');
            }
        }
        $workingDirectory = $request->body['workingDirectory'] ?? '';
        if (!is_string($workingDirectory)) throw new RequestValidationException('Некорректная рабочая папка.');

        return new static(rawurldecode($request->route['name']), trim($request->body['schedule']), trim($request->body['command']), trim($workingDirectory));
    }
}
