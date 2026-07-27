<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

/** Successful login or session-refresh response. */
final readonly class AuthResponseDto implements \JsonSerializable
{
    public function __construct(public string $login, public string $token, public int $expiresIn)
    {
    }

    /** @return array{login: string, token: string, expiresIn: int} */
    public function jsonSerialize(): array
    {
        return ['login' => $this->login, 'token' => $this->token, 'expiresIn' => $this->expiresIn];
    }
}
