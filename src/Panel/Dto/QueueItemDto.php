<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class QueueItemDto implements \JsonSerializable
{
    public function __construct(
        public string $file,
        public string $status,
        public string $queuedAt,
        public string $code,
    ) {
    }

    /** @return array{file: string, status: string, queuedAt: string, code: string} */
    public function jsonSerialize(): array
    {
        return ['file' => $this->file, 'status' => $this->status, 'queuedAt' => $this->queuedAt, 'code' => $this->code];
    }
}
