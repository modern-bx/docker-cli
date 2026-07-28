<?php

declare(strict_types=1);

namespace DockerCli\Panel\Http;

use DockerCli\Panel\Http\Attribute\Route;
use DockerCli\Panel\Http\Middleware\AuthMiddleware;
use Psr\Http\Message\ServerRequestInterface;

final class ControllerInvoker
{
    /** @param array<string, string> $variables */
    public function invoke(object $controller, string $method, Route $route, ServerRequestInterface $request, array $variables): object
    {
        $body = [];
        $rawBody = (string) $request->getBody();
        if ($rawBody !== '') {
            try {
                $decoded = json_decode($rawBody, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RequestValidationException('Некорректный запрос.');
            }
            if (!is_array($decoded)) {
                throw new RequestValidationException('Некорректный запрос.');
            }
            $body = $decoded;
        }

        $requestClass = $route->request;
        if (!is_subclass_of($requestClass, RequestDto::class)) {
            throw new \LogicException(sprintf('%s must implement %s.', $requestClass, RequestDto::class));
        }
        $dto = $requestClass::fromRequest(new RequestData($variables, $body, $request->getQueryParams(), $request->getAttribute(AuthMiddleware::LOGIN_ATTRIBUTE)));
        $response = $controller->{$method}($dto);
        if (!$response instanceof $route->response) {
            throw new \LogicException(sprintf('%s::%s() must return %s.', $controller::class, $method, $route->response));
        }

        return $response;
    }
}
