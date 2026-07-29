<?php

declare(strict_types=1);

namespace DockerCli\Panel\Http\Middleware;

use DockerCli\Panel\Dto\ErrorResponseDto;
use DockerCli\Panel\Http\ResponseEmitter;
use DockerCli\Panel\JwtTokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AuthMiddleware implements Middleware
{
    public const LOGIN_ATTRIBUTE = 'panel.authenticated_login';
    public const SESSION_STARTED_AT_ATTRIBUTE = 'panel.session_started_at';

    public function __construct(private JwtTokenService $tokens, private ResponseEmitter $responses)
    {
    }

    public function process(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $token = $request->getCookieParams()[JwtTokenService::COOKIE] ?? null;
        $login = is_string($token) ? $this->tokens->login($token) : null;
        $sessionStartedAt = is_string($token) ? $this->tokens->sessionStartedAt($token) : null;
        if ($login === null || $sessionStartedAt === null) {
            return $this->responses->json(401, new ErrorResponseDto('Сессия истекла.'));
        }

        return $next($request
            ->withAttribute(self::LOGIN_ATTRIBUTE, $login)
            ->withAttribute(self::SESSION_STARTED_AT_ATTRIBUTE, $sessionStartedAt));
    }
}
