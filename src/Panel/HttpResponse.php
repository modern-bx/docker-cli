<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class HttpResponse
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly JwtTokenService $tokens,
        private readonly string $assetsDirectory,
        private readonly ?ProjectController $projects = null,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($request->getMethod() === 'POST' && $path === '/api/auth/login') {
            return $this->login($request);
        }
        if ($request->getMethod() === 'GET' && $path === '/api/auth/session') {
            return $this->session($request);
        }
        if ($request->getMethod() === 'GET' && $path === '/api/projects') {
            if ($this->authenticatedLogin($request) === null) {
                return $this->json(401, ['error' => 'Сессия истекла.']);
            }

            return $this->json(200, ($this->projects ?? new ProjectController(new \DockerCli\Project\ProjectRegistry()))->index());
        }
        if ($request->getMethod() === 'GET' && ($path === '/' || str_starts_with($path, '/assets/'))) {
            return $this->asset($path === '/' ? 'index.html' : ltrim($path, '/'));
        }

        return $this->json(404, ['error' => 'Страница не найдена.']);
    }

    private function login(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = json_decode((string) $request->getBody(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(400, ['error' => 'Некорректный запрос.']);
        }
        $login = is_array($body) && is_string($body['login'] ?? null) ? $body['login'] : '';
        $password = is_array($body) && is_string($body['password'] ?? null) ? $body['password'] : '';
        try {
            $valid = $this->users->verifyPassword($login, $password);
            $login = UserRepository::normalizeLogin($login);
        } catch (\InvalidArgumentException) {
            $valid = false;
        }
        if (!$valid) {
            return $this->json(401, ['error' => 'Неверный логин или пароль.']);
        }

        return $this->authorized($login);
    }

    private function session(ServerRequestInterface $request): ResponseInterface
    {
        $login = $this->authenticatedLogin($request);
        if ($login === null) {
            return $this->json(401, ['error' => 'Сессия истекла.']);
        }

        return $this->authorized($login);
    }

    private function authenticatedLogin(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');

        return str_starts_with($header, 'Bearer ') ? $this->tokens->login(substr($header, 7)) : null;
    }

    private function authorized(string $login): ResponseInterface
    {
        return $this->json(200, [
            'login' => $login,
            'token' => $this->tokens->issue($login),
            'expiresIn' => JwtTokenService::LIFETIME,
        ]);
    }

    private function asset(string $relativePath): ResponseInterface
    {
        if (str_contains($relativePath, '..')) {
            return $this->json(404, ['error' => 'Страница не найдена.']);
        }
        $file = $this->assetsDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($file) || ($contents = file_get_contents($file)) === false) {
            return $this->json(404, ['error' => 'Ресурс не найден.']);
        }
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        $contentType = match ($extension) {
            'html' => 'text/html; charset=UTF-8',
            'js' => 'text/javascript; charset=UTF-8',
            'css' => 'text/css; charset=UTF-8',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };

        return new Response(200, ['Content-Type' => $contentType, 'Cache-Control' => $extension === 'html' ? 'no-store' : 'public, max-age=31536000, immutable'], $contents);
    }

    /** @param array<string, mixed>|\JsonSerializable $body */
    private function json(int $status, array|\JsonSerializable $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store'], json_encode($body, JSON_THROW_ON_ERROR));
    }
}
