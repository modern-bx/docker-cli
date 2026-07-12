<?php

declare(strict_types=1);

namespace DockerCli\Framework\Detector;

use DockerCli\Framework\Detected\DetectedFramework;
use DockerCli\Framework\Detected\Symfony;

final class SymfonyDetector extends ComposerPackageDetector
{
    public function detect(string $projectRoot): ?DetectedFramework
    {
        if (!$this->hasComposerPackage($projectRoot, 'symfony/framework-bundle')) {
            return null;
        }

        if (!is_file($projectRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'console')) {
            return null;
        }

        return new Symfony($projectRoot);
    }
}
