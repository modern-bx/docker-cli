<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

/** JSON request accepted by POST /api/projects/{name}/notes. */
final readonly class ProjectNotesRequestDto implements RequestDto
{
    /** @param list<mixed> $tags */
    public function __construct(public string $name, public array $tags, public string $description)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        if (!is_array($request->body['tags'] ?? null) || !is_string($request->body['description'] ?? null)) {
            throw new RequestValidationException('Некорректные данные заметок.');
        }

        return new static(rawurldecode($request->route['name']), array_values($request->body['tags']), $request->body['description']);
    }
}
