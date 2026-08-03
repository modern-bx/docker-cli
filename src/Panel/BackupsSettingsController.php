<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use DockerCli\Panel\Dto\BackupsSettingsDto;
use DockerCli\Panel\Dto\Request\EmptyRequestDto;
use DockerCli\Panel\Dto\Request\BackupsSettingsRequestDto;
use DockerCli\Panel\Http\Attribute\Route;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class BackupsSettingsController
{
    public function __construct(private BackupsSettingsRepository $settings)
    {
    }

    #[Route('GET', '/api/settings/backups', EmptyRequestDto::class, BackupsSettingsDto::class)]
    public function get(EmptyRequestDto $request): BackupsSettingsDto
    {
        return new BackupsSettingsDto($this->settings->locations(), $this->settings->fileStrategies());
    }

    #[Route('POST', '/api/settings/backups', BackupsSettingsRequestDto::class, BackupsSettingsDto::class)]
    public function save(BackupsSettingsRequestDto $request): BackupsSettingsDto
    {
        try {
            $settings = $this->settings->save($request->locations, $request->fileStrategies);
        } catch (\InvalidArgumentException $exception) {
            throw new RequestValidationException($exception->getMessage());
        }
        return new BackupsSettingsDto($settings['locations'], $settings['fileStrategies']);
    }
}
