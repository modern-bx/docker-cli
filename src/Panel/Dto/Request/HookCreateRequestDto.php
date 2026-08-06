<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class HookCreateRequestDto implements RequestDto
{
    public function __construct(
        public string $name,
        public bool $enabled,
        public string $level,
        public string $command,
        public string $timing,
    ) {
    }

    public static function fromRequest(RequestData $request): static
    {
        $name = $request->body['name'] ?? null;
        $enabled = $request->body['enabled'] ?? null;
        $level = $request->body['level'] ?? null;
        $command = $request->body['command'] ?? null;
        $timing = $request->body['timing'] ?? null;
        if (!is_string($name) || !is_bool($enabled) || !is_string($level) || !is_string($command) || !is_string($timing)) {
            throw new RequestValidationException('Некорректные параметры хука.');
        }

        return new static($name, $enabled, $level, $command, $timing);
    }
}
