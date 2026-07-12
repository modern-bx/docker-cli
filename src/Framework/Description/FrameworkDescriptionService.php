<?php

declare(strict_types=1);

namespace DockerCli\Framework\Description;

use DockerCli\Framework\Detected\Bitrix;
use DockerCli\Framework\Detected\Bitrix24;
use DockerCli\Framework\Detected\DetectedFramework;
use DockerCli\Framework\Detected\Laravel;
use DockerCli\Framework\Detected\Symfony;

final class FrameworkDescriptionService
{
    public function describe(DetectedFramework $framework): FrameworkDescription
    {
        return match (true) {
            $framework instanceof Bitrix24 => new FrameworkDescription(FrameworkName::Bitrix24, FrameworkCodeName::Bitrix24),
            $framework instanceof Bitrix => new FrameworkDescription(FrameworkName::Bitrix, FrameworkCodeName::Bitrix),
            $framework instanceof Laravel => new FrameworkDescription(FrameworkName::Laravel, FrameworkCodeName::Laravel),
            $framework instanceof Symfony => new FrameworkDescription(FrameworkName::Symfony, FrameworkCodeName::Symfony),
            default => throw new \InvalidArgumentException(sprintf('Unknown framework class "%s".', $framework::class)),
        };
    }
}
