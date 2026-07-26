<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Project\ProjectRegistry;

final class ProjectEnableCommand extends ProjectStateCommand
{
    public function __construct(?ProjectRegistry $registry = null)
    {
        parent::__construct('project:enable', true, $registry);
        $this->setDescription('Включить зарегистрированный проект.');
    }
}
