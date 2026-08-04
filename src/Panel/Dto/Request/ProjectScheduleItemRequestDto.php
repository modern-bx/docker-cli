<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class ProjectScheduleItemRequestDto implements RequestDto
{
    public function __construct(public string $name, public int $index) {}

    public static function fromRequest(RequestData $request): static
    {
        $index = filter_var($request->route['index'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($index === false) throw new RequestValidationException('Некорректный номер записи расписания.');

        return new static(rawurldecode($request->route['name']), $index);
    }
}
