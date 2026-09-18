<?php

declare(strict_types=1);

namespace DockerCli\Command;

interface ContextUser
{
    public function getOrigin(): string;

    public function getClass(): string;
}
