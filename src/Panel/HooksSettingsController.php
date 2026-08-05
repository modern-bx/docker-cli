<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use DockerCli\Panel\Dto\HookContentDto;
use DockerCli\Panel\Dto\HookListDto;
use DockerCli\Panel\Dto\Request\EmptyRequestDto;
use DockerCli\Panel\Dto\Request\HookActionRequestDto;
use DockerCli\Panel\Dto\Request\HookContentRequestDto;
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

    #[Route('GET', '/api/settings/hooks/{id}/content', HookActionRequestDto::class, HookContentDto::class)]
    public function content(HookActionRequestDto $request): HookContentDto
    {
        try {
            return new HookContentDto($this->hooks->content($request->id));
        } catch (\RuntimeException $exception) {
            throw new RequestValidationException($exception->getMessage());
        }
    }

    #[Route('POST', '/api/settings/hooks/{id}/content', HookContentRequestDto::class, HookContentDto::class)]
    public function save(HookContentRequestDto $request): HookContentDto
    {
        try {
            $this->hooks->save($request->id, $request->content);

            return new HookContentDto($request->content);
        } catch (\RuntimeException $exception) {
            throw new RequestValidationException($exception->getMessage());
        }
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
