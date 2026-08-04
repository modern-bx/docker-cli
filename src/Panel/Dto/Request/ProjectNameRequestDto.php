<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;

final readonly class ProjectNameRequestDto implements RequestDto
{
    public function __construct(public string $name) {}

    public static function fromRequest(RequestData $request): static
    {
        return new static(rawurldecode($request->route['name']));
    }
}
