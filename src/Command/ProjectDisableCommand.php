<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Project\ProjectRegistry;

final class ProjectDisableCommand extends ProjectStateCommand
{
    public function __construct(?ProjectRegistry $registry = null)
    {
        parent::__construct('project:disable', false, $registry);
        $this->setDescription('Отключить зарегистрированный проект.');
    }
}
