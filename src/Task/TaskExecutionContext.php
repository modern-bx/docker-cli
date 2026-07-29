<?php

declare(strict_types=1);

namespace DockerCli\Task;

final class TaskExecutionContext
{
    public const CODE_ENVIRONMENT_VARIABLE = 'DOCKER_CLI_TASK_CODE';

    /** @param array<string, string> $environment @return array<string, string> */
    public static function apply(array $environment, string $code): array
    {
        $environment[self::CODE_ENVIRONMENT_VARIABLE] = $code;

        return $environment;
    }

    public static function active(): bool
    {
        $code = getenv(self::CODE_ENVIRONMENT_VARIABLE);

        return is_string($code) && $code !== '';
    }
}
