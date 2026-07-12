<?php

declare(strict_types=1);

namespace DockerCli\Framework\Detected;

abstract class DetectedFramework
{
    public function __construct(
        protected readonly string $projectRoot,
    ) {
    }

    public function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    abstract public function getDocumentRoot(): string;
}
