<?php

declare(strict_types=1);

namespace DockerCli\Panel\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface Middleware
{
    /** @param callable(ServerRequestInterface): ResponseInterface $next */
    public function process(ServerRequestInterface $request, callable $next): ResponseInterface;
}
