<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

/** JSON request accepted by POST /api/projects/{project}/update. */
final readonly class ProjectUpdateRequestDto implements RequestDto
{
    public function __construct(public string $project, public ?string $name, public ?string $language, public ?string $framework)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        foreach (['name', 'language', 'framework'] as $field) {
            if (array_key_exists($field, $request->body) && !is_string($request->body[$field])) {
                throw new RequestValidationException('Параметры проекта должны быть строками.');
            }
        }
        return new static(
            rawurldecode($request->route['name']),
            isset($request->body['name']) && $request->body['name'] !== '' ? $request->body['name'] : null,
            isset($request->body['language']) && $request->body['language'] !== '' ? $request->body['language'] : null,
            array_key_exists('framework', $request->body) ? $request->body['framework'] : null,
        );
    }
}
