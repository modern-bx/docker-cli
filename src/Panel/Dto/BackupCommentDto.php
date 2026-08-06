<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class BackupCommentDto implements \JsonSerializable
{
    public function __construct(public string $comment)
    {
    }

    public function jsonSerialize(): array
    {
        return ['comment' => $this->comment];
    }
}
