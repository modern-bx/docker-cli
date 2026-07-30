<?php

declare(strict_types=1);

namespace DockerCli\Command;

enum MessageLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Comment = 'comment';
    case Warning = 'warning';
    case Error = 'error';
}
