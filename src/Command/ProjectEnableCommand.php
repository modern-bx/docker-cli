<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Hook\CommandHookRunner;
use DockerCli\Project\ProjectRegistry;

final class ProjectEnableCommand extends ProjectStateCommand
{
    public function __construct(?ProjectRegistry $registry = null, ?CommandHookRunner $hookRunner = null)
    {
        parent::__construct('project:enable', true, $registry, $hookRunner);
        $this->setDescription('Включить зарегистрированный проект.');
    }
}
