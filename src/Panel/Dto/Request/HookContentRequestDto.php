<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class HookContentRequestDto implements RequestDto
{
    public function __construct(public string $id, public string $content)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $action = HookActionRequestDto::fromRequest($request);
        $content = $request->body['content'] ?? null;
        if (!is_string($content)) {
            throw new RequestValidationException('Некорректное содержимое хука.');
        }

        return new static($action->id, $content);
    }
}
