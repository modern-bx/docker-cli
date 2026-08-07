<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class HookContentRequestDto implements RequestDto
{
    public function __construct(
        public string $id,
        public string $content,
        public string $name,
        public bool $enabled,
        public string $command,
        public string $timing,
    )
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $action = HookActionRequestDto::fromRequest($request);
        $content = $request->body['content'] ?? null;
        $name = $request->body['name'] ?? null;
        $enabled = $request->body['enabled'] ?? null;
        $command = $request->body['command'] ?? null;
        $timing = $request->body['timing'] ?? null;
        if (!is_string($content) || !is_string($name) || !is_bool($enabled) || !is_string($command) || !is_string($timing)) {
            throw new RequestValidationException('Некорректное содержимое хука.');
        }

        return new static($action->id, $content, $name, $enabled, $command, $timing);
    }
}
