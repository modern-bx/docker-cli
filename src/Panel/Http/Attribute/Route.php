<?php

declare(strict_types=1);

namespace DockerCli\Panel\Http\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class Route
{
    /** @param class-string $request @param class-string $response */
    public function __construct(
        public string $method,
        public string $path,
        public string $request,
        public string $response,
        public bool $authenticated = true,
    ) {
    }
}
