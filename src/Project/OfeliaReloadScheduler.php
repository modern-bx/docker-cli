<?php

declare(strict_types=1);

namespace DockerCli\Project;

use DockerCli\Queue\QueueRepository;

final class OfeliaReloadScheduler
{
    public function __construct(private readonly ?QueueRepository $queues = null) {}

    public function enqueue(): string
    {
        $item = ['meta' => ['schema' => 'queue-item', 'version' => '0.1'], 'queue-item' => ['tasks' => [[
            'code' => 'core.system.restart',
            'arguments' => ['service' => ['value' => 'ofelia']],
        ]]]];

        return ($this->queues ?? new QueueRepository())->create('default', 'core.system.restart', $item);
    }
}
