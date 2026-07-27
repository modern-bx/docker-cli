<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;

/** Request without a JSON body or route parameters. */
final readonly class EmptyRequestDto implements RequestDto
{
    public static function fromRequest(RequestData $request): static
    {
        return new static();
    }
}
