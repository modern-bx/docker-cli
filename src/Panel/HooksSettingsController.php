<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use DockerCli\Panel\Dto\HookListDto;
use DockerCli\Panel\Dto\Request\EmptyRequestDto;
use DockerCli\Panel\Dto\Request\HookActionRequestDto;
use DockerCli\Panel\Http\Attribute\Route;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class HooksSettingsController
{
    public function __construct(private HookRepository $hooks)
    {
    }

    #[Route('GET', '/api/settings/hooks', EmptyRequestDto::class, HookListDto::class)]
    public function list(EmptyRequestDto $request): HookListDto
    {
        return new HookListDto($this->hooks->all());
    }

    #[Route('POST', '/api/settings/hooks/{id}/toggle', HookActionRequestDto::class, HookListDto::class)]
    public function toggle(HookActionRequestDto $request): HookListDto
    {
        try {
            $this->hooks->toggle($request->id);
        } catch (\RuntimeException $exception) {
            throw new RequestValidationException($exception->getMessage());
        }

        return new HookListDto($this->hooks->all());
    }

    #[Route('DELETE', '/api/settings/hooks/{id}', HookActionRequestDto::class, HookListDto::class)]
    public function delete(HookActionRequestDto $request): HookListDto
    {
        try {
            $this->hooks->delete($request->id);
        } catch (\RuntimeException $exception) {
            throw new RequestValidationException($exception->getMessage());
        }

        return new HookListDto($this->hooks->all());
    }
}
