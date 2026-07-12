<?php

declare(strict_types=1);

namespace DockerCli\Framework\Detector;

use DockerCli\Framework\Detected\DetectedFramework;
use DockerCli\Framework\Detected\Laravel;

final class LaravelDetector extends ComposerPackageDetector
{
    public function detect(string $projectRoot): ?DetectedFramework
    {
        if (!$this->hasComposerPackage($projectRoot, 'laravel/framework')) {
            return null;
        }

        if (!is_file($projectRoot . DIRECTORY_SEPARATOR . 'artisan')) {
            return null;
        }

        return new Laravel($projectRoot);
    }
}
