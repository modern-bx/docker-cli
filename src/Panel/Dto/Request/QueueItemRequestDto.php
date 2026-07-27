<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class QueueItemRequestDto implements RequestDto
{
    public function __construct(public string $file)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $file = rawurldecode($request->route['file'] ?? '');
        if (basename($file) !== $file || preg_match('/^[A-Za-z0-9._-]+\.yaml$/D', $file) !== 1) {
            throw new RequestValidationException('Некорректное имя элемента очереди.');
        }
        return new static($file);
    }
}
