<?php

declare(strict_types=1);

namespace DockerCli\Command;

use Symfony\Component\Console\Command\Command;

abstract class AbstractCommand extends Command implements ContextUser
{
    public function getOrigin(): string
    {
        return str_replace(':', '.', $this->getName() ?? 'unknown');
    }

    public function getClass(): string
    {
        return 'command';
    }
}
