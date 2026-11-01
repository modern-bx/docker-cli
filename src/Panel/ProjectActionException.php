<?php

declare(strict_types=1);

namespace DockerCli\Panel;

final class ProjectActionException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 500)
    {
        parent::__construct($message);
    }
}
