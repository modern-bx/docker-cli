<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;

/** Route request for a project enable, disable, or wipe action. */
final readonly class ProjectActionRequestDto implements RequestDto
{
    public function __construct(public string $name, public string $action)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        return new static(rawurldecode($request->route['name']), $request->route['action']);
    }
}
