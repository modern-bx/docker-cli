<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class NotificationListDto implements \JsonSerializable
{
    /** @param list<NotificationDto> $notifications */
    public function __construct(public array $notifications) {}

    /** @return array{notifications: list<NotificationDto>} */
    public function jsonSerialize(): array { return ['notifications' => $this->notifications]; }
}
