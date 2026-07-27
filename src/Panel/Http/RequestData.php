<?php

declare(strict_types=1);

namespace DockerCli\Panel\Http;

final readonly class RequestData
{
    /** @param array<string, string> $route @param array<string, mixed> $body */
    public function __construct(public array $route, public array $body, public ?string $login)
    {
    }
}
