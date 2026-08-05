<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class HookRunResultDto implements \JsonSerializable
{
    public function __construct(public int $exitCode, public string $stdout, public string $stderr, public string $workingDirectory)
    {
    }

    /** @return array{exitCode: int, stdout: string, stderr: string, workingDirectory: string} */
    public function jsonSerialize(): array
    {
        return ['exitCode' => $this->exitCode, 'stdout' => $this->stdout, 'stderr' => $this->stderr, 'workingDirectory' => $this->workingDirectory];
    }
}
