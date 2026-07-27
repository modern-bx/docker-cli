<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use DockerCli\Panel\Dto\AuthResponseDto;
use DockerCli\Panel\Dto\Request\LoginRequestDto;
use DockerCli\Panel\Dto\Request\SessionRequestDto;
use DockerCli\Panel\Http\Attribute\Route;
use DockerCli\Panel\Http\UnauthorizedException;

final readonly class AuthController
{
    public function __construct(private UserRepository $users, private JwtTokenService $tokens)
    {
    }

    #[Route('POST', '/api/auth/login', LoginRequestDto::class, AuthResponseDto::class, authenticated: false)]
    public function login(LoginRequestDto $request): AuthResponseDto
    {
        try {
            $valid = $this->users->verifyPassword($request->login, $request->password);
            $login = UserRepository::normalizeLogin($request->login);
        } catch (\InvalidArgumentException) {
            $valid = false;
        }
        if (!$valid) {
            throw new UnauthorizedException('Неверный логин или пароль.');
        }

        return $this->authorized($login);
    }

    #[Route('GET', '/api/auth/session', SessionRequestDto::class, AuthResponseDto::class)]
    public function session(SessionRequestDto $request): AuthResponseDto
    {
        return $this->authorized($request->login);
    }

    private function authorized(string $login): AuthResponseDto
    {
        return new AuthResponseDto($login, $this->tokens->issue($login), JwtTokenService::LIFETIME);
    }
}
