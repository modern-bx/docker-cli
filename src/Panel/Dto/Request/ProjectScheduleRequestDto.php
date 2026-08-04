<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class ProjectScheduleRequestDto implements RequestDto
{
    public function __construct(public string $name, public ?int $index, public bool $enabled, public string $schedule, public string $command, public string $workingDirectory)
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
        if (!is_bool($request->body['enabled'] ?? null)) throw new RequestValidationException('Некорректный статус команды.');

        $index = isset($request->route['index']) ? filter_var($request->route['index'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : null;
        if ($index === false) throw new RequestValidationException('Некорректный номер записи расписания.');

        return new static(rawurldecode($request->route['name']), $index, $request->body['enabled'], trim($request->body['schedule']), trim($request->body['command']), trim($workingDirectory));
    }
}
