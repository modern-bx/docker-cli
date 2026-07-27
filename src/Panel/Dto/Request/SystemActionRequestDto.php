<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Enum\SystemActionEnum;
use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

/** Route request for a global or service-specific system action. */
final readonly class SystemActionRequestDto implements RequestDto
{
    public function __construct(public SystemActionEnum $action, public ?string $service = null)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $action = SystemActionEnum::tryFrom($request->route['action']);
        if ($action === null) {
            throw new RequestValidationException('Неизвестное системное действие.');
        }

        return new static($action, isset($request->route['service']) ? rawurldecode($request->route['service']) : null);
    }
}
