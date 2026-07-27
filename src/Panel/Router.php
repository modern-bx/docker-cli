<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use DockerCli\Panel\Dto\ErrorResponseDto;
use DockerCli\Panel\Http\Attribute\Route;
use DockerCli\Panel\Http\ControllerInvoker;
use DockerCli\Panel\Http\Middleware\AuthMiddleware;
use DockerCli\Panel\Http\RequestValidationException;
use DockerCli\Panel\Http\ResponseEmitter;
use DockerCli\Panel\Http\UnauthorizedException;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

use function FastRoute\simpleDispatcher;

final readonly class Router
{
    private Dispatcher $dispatcher;

    /** @param list<object> $controllers */
    public function __construct(
        array $controllers,
        private ControllerInvoker $invoker,
        private AuthMiddleware $auth,
        private ResponseEmitter $responses,
    ) {
        $this->dispatcher = simpleDispatcher(static function (RouteCollector $routes) use ($controllers): void {
            foreach ($controllers as $controller) {
                $reflection = new \ReflectionObject($controller);
                foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    $attributes = $method->getAttributes(Route::class);
                    if ($method->isConstructor()) {
                        continue;
                    }
                    if ($attributes === []) {
                        throw new \LogicException(sprintf('Public controller method %s::%s() must declare a Route attribute.', $controller::class, $method->getName()));
                    }
                    foreach ($attributes as $attribute) {
                        $route = $attribute->newInstance();
                        $routes->addRoute($route->method, $route->path, [$controller, $method->getName(), $route]);
                    }
                }
            }
        });
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $match = $this->dispatcher->dispatch($request->getMethod(), $request->getUri()->getPath());
        if ($match[0] === Dispatcher::NOT_FOUND) {
            return $this->responses->json(404, new ErrorResponseDto('Страница не найдена.'));
        }
        if ($match[0] === Dispatcher::METHOD_NOT_ALLOWED) {
            return new Response(405, ['Allow' => implode(', ', $match[1])]);
        }

        [$controller, $method, $route] = $match[1];
        $handler = function (ServerRequestInterface $request) use ($controller, $method, $route, $match): ResponseInterface {
            try {
                return $this->responses->emit($this->invoker->invoke($controller, $method, $route, $request, $match[2]));
            } catch (RequestValidationException $exception) {
                return $this->responses->json(400, new ErrorResponseDto($exception->getMessage()));
            } catch (UnauthorizedException $exception) {
                return $this->responses->json(401, new ErrorResponseDto($exception->getMessage()));
            } catch (ProjectActionException|SystemActionException $exception) {
                return $this->responses->json($exception->httpStatus, new ErrorResponseDto($exception->getMessage()));
            }
        };

        return $route->authenticated ? $this->auth->process($request, $handler) : $handler($request);
    }
}
