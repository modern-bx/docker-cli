<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use DockerCli\Panel\Dto\Request\EmptyRequestDto;
use DockerCli\Panel\Dto\Request\SecuritySettingsRequestDto;
use DockerCli\Panel\Dto\SecuritySettingsDto;
use DockerCli\Panel\Http\Attribute\Route;

final readonly class SecuritySettingsController
{
    public function __construct(private SecuritySettingsRepository $settings)
    {
    }

    #[Route('GET', '/api/settings/security', EmptyRequestDto::class, SecuritySettingsDto::class)]
    public function get(EmptyRequestDto $request): SecuritySettingsDto
    {
        return new SecuritySettingsDto($this->settings->sessionHours());
    }

    #[Route('POST', '/api/settings/security', SecuritySettingsRequestDto::class, SecuritySettingsDto::class)]
    public function save(SecuritySettingsRequestDto $request): SecuritySettingsDto
    {
        $this->settings->saveSessionHours($request->maximumSessionHours);
        return new SecuritySettingsDto($request->maximumSessionHours);
    }
}
