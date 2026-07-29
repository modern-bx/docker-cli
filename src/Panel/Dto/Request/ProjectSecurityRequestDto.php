<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

/** JSON request accepted by POST /api/projects/{name}/security. */
final readonly class ProjectSecurityRequestDto implements RequestDto
{
    public function __construct(public string $name, public bool $protected)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        if (!is_bool($request->body['protected'] ?? null)) {
            throw new RequestValidationException('Некорректные настройки безопасности.');
        }

        return new static(rawurldecode($request->route['name']), $request->body['protected']);
    }
}
