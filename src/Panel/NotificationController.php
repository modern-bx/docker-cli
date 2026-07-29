<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use DockerCli\Notification\NotificationRepository;
use DockerCli\Panel\Dto\NotificationDto;
use DockerCli\Panel\Dto\NotificationListDto;
use DockerCli\Panel\Dto\Request\EmptyRequestDto;
use DockerCli\Panel\Dto\Request\NotificationRequestDto;
use DockerCli\Panel\Http\Attribute\Route;

final readonly class NotificationController
{
    public function __construct(private NotificationRepository $notifications) {}

    #[Route('GET', '/api/notifications', EmptyRequestDto::class, NotificationListDto::class)]
    public function current(EmptyRequestDto $request): NotificationListDto
    {
        return new NotificationListDto(array_map(
            static fn (array $item): NotificationDto => new NotificationDto($item['file'], $item['time'], $item['level'], $item['message']),
            $this->notifications->current(),
        ));
    }

    #[Route('DELETE', '/api/notifications/{file}', NotificationRequestDto::class, NotificationListDto::class)]
    public function archive(NotificationRequestDto $request): NotificationListDto
    {
        try {
            $this->notifications->archive($request->file);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            throw new NotificationActionException($exception->getMessage());
        }
        return $this->current(new EmptyRequestDto());
    }
}
