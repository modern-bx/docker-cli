<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Enum\ProjectActionEnum;
use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

/** Route request for a project enable, disable, wipe, or delete action. */
final readonly class ProjectActionRequestDto implements RequestDto
{
    public function __construct(public string $name, public ProjectActionEnum $action)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $action = ProjectActionEnum::tryFrom($request->route['action']);
        if ($action === null) {
            throw new RequestValidationException('Неизвестное действие над проектом.');
        }

        return new static(rawurldecode($request->route['name']), $action);
    }
}
