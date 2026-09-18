<?php

declare(strict_types=1);

namespace DockerCli\Panel;

final class QueueActionException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 409)
    {
        parent::__construct($message);
    }
}
