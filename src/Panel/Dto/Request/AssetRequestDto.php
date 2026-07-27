<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;

/** Route request for a compiled panel asset. */
final readonly class AssetRequestDto implements RequestDto
{
    public function __construct(public string $path)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        return new static($request->route['path'] ?? 'index.html');
    }
}
