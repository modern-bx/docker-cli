<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use DockerCli\Panel\Dto\Request\EmptyRequestDto;
use DockerCli\Panel\Dto\Request\SecuritySettingsRequestDto;
use DockerCli\Panel\Dto\SecuritySettingsDto;
use DockerCli\Panel\Http\Attribute\Route;
use DockerCli\Queue\QueueRepository;

final readonly class SecuritySettingsController
{
    public function __construct(private SecuritySettingsRepository $settings, private ?QueueRepository $queues = null)
    {
    }

    #[Route('GET', '/api/settings/security', EmptyRequestDto::class, SecuritySettingsDto::class)]
    public function get(EmptyRequestDto $request): SecuritySettingsDto
    {
        $auth = $this->settings->httpAuth();
        return new SecuritySettingsDto($this->settings->sessionHours(), $auth['login'], $auth['password']);
    }

    #[Route('POST', '/api/settings/security', SecuritySettingsRequestDto::class, SecuritySettingsDto::class)]
    public function save(SecuritySettingsRequestDto $request): SecuritySettingsDto
    {
        $this->settings->saveSessionHours($request->maximumSessionHours);
        $this->settings->saveHttpAuth($request->httpAuthLogin, $request->httpAuthPassword);
        $file = $this->enqueueHttpAuthApply();
        return new SecuritySettingsDto($request->maximumSessionHours, $request->httpAuthLogin, $request->httpAuthPassword, $file);
    }

    private function enqueueHttpAuthApply(): string
    {
        $item = [
            'meta' => ['schema' => 'queue-item', 'version' => '0.1'],
            'queue-item' => ['tasks' => [
                ['code' => 'core.config.init', 'arguments' => ['rebuild' => ['value' => true]]],
                ['code' => 'core.system.reload', 'arguments' => ['service' => ['value' => 'openresty,panel-gateway']]],
            ]],
        ];

        try {
            return ($this->queues ?? new QueueRepository())->create('default', 'core.config.init', $item);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            throw new SystemActionException($exception->getMessage(), 500);
        }
    }
}
