<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class NotificationDto implements \JsonSerializable
{
    public function __construct(public string $file, public string $time, public string $level, public string $message)
    {
    }

    /** @return array{file: string, time: string, level: string, message: string} */
    public function jsonSerialize(): array
    {
        return ['file' => $this->file, 'time' => $this->time, 'level' => $this->level, 'message' => $this->message];
    }
}
