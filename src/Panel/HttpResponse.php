<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use DockerCli\Panel\Dto\PanelStateDto;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

use function FastRoute\simpleDispatcher;

final class HttpResponse
{
    private Dispatcher $router;

    public function __construct(
        private readonly UserRepository $users,
        private readonly JwtTokenService $tokens,
        private readonly string $assetsDirectory,
        private readonly ?ProjectController $projects = null,
        private readonly ?SystemController $system = null,
    ) {
        $this->router = simpleDispatcher(static function (RouteCollector $routes): void {
            $routes->addRoute('POST', '/api/auth/login', ['login', false]);
            $routes->addRoute('GET', '/api/auth/session', ['session', false]);
            $routes->addRoute('GET', '/api/state', ['state', true]);
            $routes->addRoute('GET', '/api/projects', ['projects', true]);
            $routes->addRoute('POST', '/api/projects/{name}/{action:enable|disable|wipe}', ['projectAction', true]);
            $routes->addRoute('POST', '/api/projects/{name}/notes', ['saveProjectNotes', true]);
            $routes->addRoute('GET', '/api/system', ['systemStatus', true]);
            $routes->addRoute('POST', '/api/system/{action:start|stop|restart}', ['systemAction', true]);
            $routes->addRoute('POST', '/api/system/services/{service}/{action:start|stop|restart}', ['systemServiceAction', true]);
            $routes->addRoute('GET', '/', ['index', false]);
            $routes->addRoute('GET', '/assets/{path:.+}', ['assetRoute', false]);
        });
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $route = $this->router->dispatch($request->getMethod(), $request->getUri()->getPath());
        if ($route[0] === Dispatcher::NOT_FOUND) {
            return $this->json(404, ['error' => 'Страница не найдена.']);
        }
        if ($route[0] === Dispatcher::METHOD_NOT_ALLOWED) {
            return new Response(405, ['Allow' => implode(', ', $route[1])]);
        }

        [$handler, $authenticated] = $route[1];
        if ($authenticated && $this->authenticatedLogin($request) === null) {
            return $this->json(401, ['error' => 'Сессия истекла.']);
        }

        return $this->{$handler}($request, $route[2]);
    }

    /** @param array<string, string> $variables */
    private function state(ServerRequestInterface $request, array $variables): ResponseInterface
    {
        $projects = ($this->projects ?? new ProjectController(new \DockerCli\Project\ProjectRegistry()))->index();
        $system = ($this->system ?? new SystemController(new \DockerCli\Config\SystemCompose()))->status();

        return $this->json(200, new PanelStateDto($projects->projects, $system));
    }

    /** @param array<string, string> $variables */
    private function projects(ServerRequestInterface $request, array $variables): ResponseInterface
    {
        return $this->json(200, ($this->projects ?? new ProjectController(new \DockerCli\Project\ProjectRegistry()))->index());
    }

    /** @param array{name: string, action: string} $variables */
    private function projectAction(ServerRequestInterface $request, array $variables): ResponseInterface
    {
        try {
            return $this->json(200, ($this->projects ?? new ProjectController(new \DockerCli\Project\ProjectRegistry()))->act(
                rawurldecode($variables['name']),
                $variables['action'],
            ));
        } catch (ProjectActionException $exception) {
            return $this->json($exception->httpStatus, ['error' => $exception->getMessage()]);
        }
    }

    /** @param array{name: string} $variables */
    private function saveProjectNotes(ServerRequestInterface $request, array $variables): ResponseInterface
    {
        try {
            $body = json_decode((string) $request->getBody(), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($body) || !is_array($body['tags'] ?? null) || !is_string($body['description'] ?? null)) {
                throw new ProjectActionException('Некорректные данные заметок.', 400);
            }

            return $this->json(200, ($this->projects ?? new ProjectController(new \DockerCli\Project\ProjectRegistry()))->saveNotes(
                rawurldecode($variables['name']),
                array_values($body['tags']),
                $body['description'],
            ));
        } catch (\JsonException) {
            return $this->json(400, ['error' => 'Некорректный запрос.']);
        } catch (ProjectActionException $exception) {
            return $this->json($exception->httpStatus, ['error' => $exception->getMessage()]);
        }
    }

    /** @param array<string, string> $variables */
    private function systemStatus(ServerRequestInterface $request, array $variables): ResponseInterface
    {
        return $this->json(200, ($this->system ?? new SystemController(new \DockerCli\Config\SystemCompose()))->status());
    }

    /** @param array{action: string} $variables */
    private function systemAction(ServerRequestInterface $request, array $variables): ResponseInterface
    {
        return $this->runSystemAction($variables['action']);
    }

    /** @param array{service: string, action: string} $variables */
    private function systemServiceAction(ServerRequestInterface $request, array $variables): ResponseInterface
    {
        return $this->runSystemAction($variables['action'], rawurldecode($variables['service']));
    }

    private function runSystemAction(string $action, ?string $service = null): ResponseInterface
    {
        try {
            return $this->json(200, ($this->system ?? new SystemController(new \DockerCli\Config\SystemCompose()))->act($action, $service));
        } catch (SystemActionException $exception) {
            return $this->json($exception->httpStatus, ['error' => $exception->getMessage()]);
        }
    }

    /** @param array<string, string> $variables */
    private function login(ServerRequestInterface $request, array $variables): ResponseInterface
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

    /** @param array<string, string> $variables */
    private function session(ServerRequestInterface $request, array $variables): ResponseInterface
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

    /** @param array<string, string> $variables */
    private function index(ServerRequestInterface $request, array $variables): ResponseInterface
    {
        return $this->asset('index.html');
    }

    /** @param array{path: string} $variables */
    private function assetRoute(ServerRequestInterface $request, array $variables): ResponseInterface
    {
        return $this->asset('assets/' . $variables['path']);
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
