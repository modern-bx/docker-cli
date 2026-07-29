<?php

declare(strict_types=1);

namespace DockerCli\Panel;

final class JwtTokenService
{
    public const LIFETIME = 600;
    public const COOKIE = '__Host-docker-cli-panel-session';
    private const ISSUER = 'docker-cli-panel';
    private const AUDIENCE = 'docker-cli-panel';
    private const CLOCK_SKEW = 30;

    public function __construct(
        private readonly string $secret,
        private readonly TokenRepository $repository,
        private readonly SecuritySettingsRepository $settings,
    )
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('JWT secret must not be empty.');
        }
    }

    public function issue(string $login, ?int $now = null, ?int $sessionStartedAt = null): string
    {
        $now ??= time();
        $sessionStartedAt ??= $now;
        $header = $this->encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $jti = bin2hex(random_bytes(12));
        $expiresAt = $now + self::LIFETIME;
        $payload = $this->encode([
            'sub' => $login,
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'iat' => $now,
            'exp' => $expiresAt,
            'jti' => $jti,
            'session_started_at' => $sessionStartedAt,
        ]);
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $header . '.' . $payload, $this->secret, true));

        $token = $header . '.' . $payload . '.' . $signature;
        $this->repository->store($token, $jti, $login, $now, $expiresAt);

        return $token;
    }

    public function login(string $token, ?int $now = null): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$header, $payload, $signature] = $parts;
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $header . '.' . $payload, $this->secret, true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }
        $decodedHeader = $this->decode($header);
        $claims = $this->decode($payload);
        $now ??= time();
        if (($decodedHeader['alg'] ?? null) !== 'HS256'
            || ($decodedHeader['typ'] ?? null) !== 'JWT'
            || !is_string($claims['sub'] ?? null)
            || ($claims['iss'] ?? null) !== self::ISSUER
            || ($claims['aud'] ?? null) !== self::AUDIENCE
            || !is_int($claims['iat'] ?? null)
            || !is_int($claims['exp'] ?? null)
            || !is_string($claims['jti'] ?? null)
            || preg_match('/^[a-f0-9]{24}$/D', $claims['jti']) !== 1
            || !is_int($claims['session_started_at'] ?? null)
            || $claims['iat'] > $now + self::CLOCK_SKEW
            || $claims['exp'] - $claims['iat'] !== self::LIFETIME
            || $claims['session_started_at'] > $claims['iat']
            || $claims['session_started_at'] + ($this->settings->sessionHours() * 3600) <= $now
            || $claims['exp'] <= $now) {
            return null;
        }

        try {
            if (UserRepository::normalizeLogin($claims['sub']) !== $claims['sub']) return null;
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->repository->contains($token, $claims['jti'], $claims['sub'], $claims['exp'], $now) ? $claims['sub'] : null;
    }

    public function sessionStartedAt(string $token, ?int $now = null): ?int
    {
        if ($this->login($token, $now) === null) return null;
        $parts = explode('.', $token);
        $claims = $this->decode($parts[1] ?? '');
        return is_int($claims['session_started_at'] ?? null) ? $claims['session_started_at'] : null;
    }

    /** @param array<string, int|string> $value */
    private function encode(array $value): string
    {
        return $this->base64UrlEncode(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    private function decode(string $value): array
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $json = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        if (!is_string($json)) {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
