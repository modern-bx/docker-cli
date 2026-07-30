<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

/** JSON request accepted by POST /api/projects/{name}/rename. */
final readonly class ProjectRenameRequestDto implements RequestDto
{
    public function __construct(public string $name, public string $code)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        if (!is_string($request->body['code'] ?? null) || $request->body['code'] === '') {
            throw new RequestValidationException('Код проекта обязателен.');
        }

        return new static(rawurldecode($request->route['name']), $request->body['code']);
    }
}
