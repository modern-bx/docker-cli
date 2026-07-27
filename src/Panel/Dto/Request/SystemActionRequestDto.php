<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;

/** Route request for a global or service-specific system action. */
final readonly class SystemActionRequestDto implements RequestDto
{
    public function __construct(public string $action, public ?string $service = null)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        return new static($request->route['action'], isset($request->route['service']) ? rawurldecode($request->route['service']) : null);
    }
}
