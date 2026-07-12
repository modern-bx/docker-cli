<?php

declare(strict_types=1);

namespace DockerCli\Framework;

use DockerCli\Framework\Detected\DetectedFramework;
use DockerCli\Framework\Detector\Bitrix24Detector;
use DockerCli\Framework\Detector\BitrixDetector;
use DockerCli\Framework\Detector\FrameworkDetectorInterface;
use DockerCli\Framework\Detector\LaravelDetector;
use DockerCli\Framework\Detector\SymfonyDetector;

final class FrameworkDetectionService
{
    /** @var list<FrameworkDetectorInterface> */
    private readonly array $detectors;

    /** @param list<FrameworkDetectorInterface>|null $detectors */
    public function __construct(?array $detectors = null)
    {
        $this->detectors = $detectors ?? self::defaultDetectors();
    }

    public static function createDefault(): self
    {
        return new self();
    }

    /** @return list<FrameworkDetectorInterface> */
    private static function defaultDetectors(): array
    {
        return [
            new Bitrix24Detector(),
            new BitrixDetector(),
            new LaravelDetector(),
            new SymfonyDetector(),
        ];
    }

    public function detect(?string $projectRoot = null): ?DetectedFramework
    {
        $projectRoot = $this->normalizeProjectRoot($projectRoot ?? getcwd() ?: '.');

        do {
            foreach ($this->detectors as $detector) {
                $framework = $detector->detect($projectRoot);
                if ($framework !== null) {
                    return $framework;
                }
            }

            $parent = dirname($projectRoot);
            if ($parent === $projectRoot) {
                return null;
            }

            $projectRoot = $parent;
        } while (true);
    }

    private function normalizeProjectRoot(string $projectRoot): string
    {
        $realPath = realpath($projectRoot);
        if ($realPath === false || !is_dir($realPath)) {
            throw new \InvalidArgumentException(sprintf('Project root "%s" does not exist or is not a directory.', $projectRoot));
        }

        return rtrim($realPath, DIRECTORY_SEPARATOR);
    }
}
