<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class QueueActionRequestDto implements RequestDto
{
    public function __construct(public string $action)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $action = $request->route['action'] ?? '';
        if (!in_array($action, ['pause', 'resume'], true)) {
            throw new RequestValidationException('Неизвестное действие над очередью.');
        }
        return new static($action);
    }
}
