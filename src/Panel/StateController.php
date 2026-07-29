<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use DockerCli\Panel\Dto\PanelStateDto;
use DockerCli\Panel\Dto\Request\EmptyRequestDto;
use DockerCli\Panel\Http\Attribute\Route;

final readonly class StateController
{
    public function __construct(private ProjectController $projects, private SystemController $system, private QueueController $queue, private NotificationController $notifications)
    {
    }

    #[Route('GET', '/api/state', EmptyRequestDto::class, PanelStateDto::class)]
    public function state(EmptyRequestDto $request): PanelStateDto
    {
        return new PanelStateDto($this->projects->projects($request)->projects, $this->system->status($request), $this->queue->state($request), $this->notifications->current($request));
    }
}
