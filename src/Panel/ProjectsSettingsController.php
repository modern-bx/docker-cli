<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use DockerCli\Panel\Dto\ProjectsSettingsDto;
use DockerCli\Panel\Dto\Request\EmptyRequestDto;
use DockerCli\Panel\Dto\Request\ProjectsSettingsRequestDto;
use DockerCli\Panel\Http\Attribute\Route;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class ProjectsSettingsController
{
    public function __construct(private ProjectsSettingsRepository $settings)
    {
    }

    #[Route('GET', '/api/settings/projects', EmptyRequestDto::class, ProjectsSettingsDto::class)]
    public function get(EmptyRequestDto $request): ProjectsSettingsDto
    {
        return new ProjectsSettingsDto($this->settings->locations());
    }

    #[Route('POST', '/api/settings/projects', ProjectsSettingsRequestDto::class, ProjectsSettingsDto::class)]
    public function save(ProjectsSettingsRequestDto $request): ProjectsSettingsDto
    {
        try {
            $this->settings->save($request->locations);
        } catch (\InvalidArgumentException $exception) {
            throw new RequestValidationException($exception->getMessage());
        }
        return new ProjectsSettingsDto($request->locations);
    }
}
