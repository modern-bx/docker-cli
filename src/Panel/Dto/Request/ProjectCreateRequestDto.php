<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class ProjectCreateRequestDto implements RequestDto
{
    public function __construct(public ?string $code, public string $location, public string $language, public ?string $framework)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $code = $request->body['code'] ?? null;
        $location = $request->body['location'] ?? null;
        $language = $request->body['language'] ?? null;
        $framework = $request->body['framework'] ?? null;
        if (($code !== null && !is_string($code)) || !is_string($location) || !is_string($language) || ($framework !== null && !is_string($framework))) {
            throw new RequestValidationException('Некорректные данные проекта.');
        }
        $code = is_string($code) && trim($code) !== '' ? trim($code) : null;
        return new static($code, $location, $language, is_string($framework) && $framework !== '' ? $framework : null);
    }
}
