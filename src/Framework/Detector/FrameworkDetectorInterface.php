<?php

declare(strict_types=1);

namespace DockerCli\Framework\Detector;

use DockerCli\Framework\Detected\DetectedFramework;

interface FrameworkDetectorInterface
{
    public function detect(string $projectRoot): ?DetectedFramework;
}
