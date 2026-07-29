<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

/** DTO returned by GET /api/state. */
final readonly class PanelStateDto implements \JsonSerializable
{
    /** @param list<ProjectDto> $projects */
    public function __construct(
        public array $projects,
        public SystemStatusDto $system,
        public QueueStateDto $queue,
        public NotificationListDto $notifications,
    ) {
    }

    /** @return array{projects: list<ProjectDto>, system: SystemStatusDto, queue: QueueStateDto, notifications: NotificationListDto} */
    public function jsonSerialize(): array
    {
        return ['projects' => $this->projects, 'system' => $this->system, 'queue' => $this->queue, 'notifications' => $this->notifications];
    }
}
