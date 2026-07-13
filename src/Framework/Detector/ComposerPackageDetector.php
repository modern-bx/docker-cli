<?php

declare(strict_types=1);

namespace DockerCli\Framework\Detector;

use function DockerCli\Util\join_path;

abstract class ComposerPackageDetector implements FrameworkDetectorInterface
{
    protected function hasComposerPackage(string $projectRoot, string $packageName): bool
    {
        $composerJson = join_path($projectRoot, 'composer.json');
        if (!is_file($composerJson)) {
            return false;
        }

        $contents = file_get_contents($composerJson);
        if ($contents === false) {
            return false;
        }

        $composer = json_decode($contents, true);
        if (!is_array($composer)) {
            return false;
        }

        return isset($composer['require'][$packageName]) || isset($composer['require-dev'][$packageName]);
    }
}
