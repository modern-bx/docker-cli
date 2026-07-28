<?php

declare(strict_types=1);

namespace DockerCli\Panel\Http;

final readonly class RequestData
{
    /** @param array<string, string> $route @param array<string, mixed> $body @param array<string, mixed> $query */
    public function __construct(public array $route, public array $body, public array $query, public ?string $login)
    {
    }
}
