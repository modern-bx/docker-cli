<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class HookActionRequestDto implements RequestDto
{
    public function __construct(public string $id)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $id = rawurldecode($request->route['id'] ?? '');
        if ($id === '' || str_contains($id, "\0") || str_contains($id, '..') || str_starts_with($id, '/') || str_starts_with($id, '\\')) {
            throw new RequestValidationException('Некорректный идентификатор хука.');
        }

        return new static($id);
    }
}
