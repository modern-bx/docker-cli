<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Hook\CommandHookRunner;
use DockerCli\Project\ProjectRegistry;

final class ProjectDisableCommand extends ProjectStateCommand
{
    public function __construct(?ProjectRegistry $registry = null, ?CommandHookRunner $hookRunner = null)
    {
        parent::__construct('project:disable', false, $registry, $hookRunner);
        $this->setDescription('Отключить зарегистрированный проект.');
    }
}
