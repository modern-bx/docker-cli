<?php

declare(strict_types=1);

namespace DockerCli\Command;

enum MessageLevel: string
{
    case Info = 'info';
    case Comment = 'comment';
    case Error = 'error';
}
