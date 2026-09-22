<?php

declare(strict_types=1);

namespace DockerCli\Tests\Integration\Config;

use DockerCli\Config\SystemCompose;
use PHPUnit\Framework\TestCase;

final class SystemComposeFeatureFlagsTest extends TestCase
{
    public function testOptionalServicesAreEnabledByDefault(): void
    {
        $this->withCompose(function (SystemCompose $compose): void {
            self::assertSame(
                "proxysql,proxyweb,dbtrail,glitchtip",
                $compose->dockerProcessEnvironment()["COMPOSE_PROFILES"],
            );
        });
    }

    public function testDisabledFeaturesAreExcludedFromComposeProfiles(): void
    {
        $this->withCompose(function (SystemCompose $compose): void {
            $environment = (string) file_get_contents($compose->envFile());
            $environment = str_replace(
                ["COMPOSE_ENABLE_PROXYSQL=1", "COMPOSE_ENABLE_DBTRAIL=1", "COMPOSE_ENABLE_GLITCHTIP=1"],
                ["COMPOSE_ENABLE_PROXYSQL=0", "COMPOSE_ENABLE_DBTRAIL=off", "COMPOSE_ENABLE_GLITCHTIP=no"],
                $environment,
            );
            file_put_contents($compose->envFile(), $environment);

            self::assertSame("", $compose->dockerProcessEnvironment()["COMPOSE_PROFILES"]);
        });
    }

    /** @param callable(SystemCompose): void $assertions */
    private function withCompose(callable $assertions): void
    {
        $previousHome = getenv("HOME");
        $featureKeys = [
            "COMPOSE_ENABLE_PROXYSQL",
            "COMPOSE_ENABLE_PROXYWEB",
            "COMPOSE_ENABLE_DBTRAIL",
            "COMPOSE_ENABLE_GLITCHTIP",
        ];
        $previousFeatures = array_map(static fn (string $key): string|false => getenv($key), $featureKeys);
        $temporaryHome = sys_get_temp_dir() . "/docker-cli-feature-flags-" . bin2hex(random_bytes(8));
        mkdir($temporaryHome, 0755, true);
        putenv("HOME=" . $temporaryHome);
        foreach ($featureKeys as $key) {
            putenv($key);
        }

        try {
            $compose = new SystemCompose();
            $compose->init();
            $assertions($compose);
        } finally {
            putenv($previousHome === false ? "HOME" : "HOME=" . $previousHome);
            foreach ($featureKeys as $index => $key) {
                $previous = $previousFeatures[$index];
                putenv($previous === false ? $key : $key . "=" . $previous);
            }
            $this->removeDirectory($temporaryHome);
        }
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isDir()) {
                rmdir($entry->getPathname());
            } elseif ($entry instanceof \SplFileInfo) {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
