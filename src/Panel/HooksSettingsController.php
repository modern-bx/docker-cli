<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use DockerCli\Panel\Dto\HookContentDto;
use DockerCli\Panel\Dto\HookListDto;
use DockerCli\Panel\Dto\HookRunResultDto;
use DockerCli\Panel\Dto\Request\EmptyRequestDto;
use DockerCli\Panel\Dto\Request\HookActionRequestDto;
use DockerCli\Panel\Dto\Request\HookContentRequestDto;
use DockerCli\Panel\Dto\Request\HookRunRequestDto;
use DockerCli\Panel\Http\Attribute\Route;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class HooksSettingsController
{
    public function __construct(
        private HookRepository $hooks,
        private ?ProjectsSettingsRepository $settings = null,
    ) {
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

    #[Route('POST', '/api/settings/hooks/{id}/run', HookRunRequestDto::class, HookRunResultDto::class)]
    public function run(HookRunRequestDto $request): HookRunResultDto
    {
        try {
            $workingDirectory = $this->workingDirectory($request->workingDirectory);
            $result = $this->hooks->run($request->id, $request->profile, $workingDirectory);

            return new HookRunResultDto($result['exitCode'], $result['stdout'], $result['stderr'], $workingDirectory);
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

    private function workingDirectory(string $workingDirectory): string
    {
        if ($workingDirectory !== '') {
            $real = realpath($workingDirectory);
            if ($real === false || !is_dir($real)) {
                throw new \RuntimeException('Рабочая директория хука не найдена.');
            }

            return $real;
        }

        foreach (($this->settings ?? new ProjectsSettingsRepository())->locations() as $location) {
            if (($location['default'] ?? false) === true && is_dir($location['path'])) {
                return $location['path'];
            }
        }
        $locations = ($this->settings ?? new ProjectsSettingsRepository())->locations();
        if (isset($locations[0]) && is_dir($locations[0]['path'])) {
            return $locations[0]['path'];
        }

        $home = getenv('HOME') ?: throw new \RuntimeException('HOME environment variable is not set.');

        return $home;
    }
}
