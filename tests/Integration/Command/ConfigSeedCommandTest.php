<?php

declare(strict_types=1);

namespace DockerCli\Tests\Integration\Command;

use DockerCli\Command\ConfigSeedCommand;
use DockerCli\Config\SystemCompose;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ConfigSeedCommandTest extends TestCase
{
    public function testSeedsProxyServiceCredentialsAndPreservesExistingValues(): void
    {
        $previousHome = getenv("HOME");
        $temporaryHome = sys_get_temp_dir() . "/docker-cli-config-seed-" . bin2hex(random_bytes(8));
        mkdir($temporaryHome, 0755, true);
        putenv("HOME=" . $temporaryHome);

        try {
            $compose = new SystemCompose();
            $compose->init();
            $environment = (string) file_get_contents($compose->envFile());
            $environment = str_replace(
                "PROXYSQL_ADMIN_USER=",
                "PROXYSQL_ADMIN_USER=operator",
                $environment,
            );
            file_put_contents($compose->envFile(), $environment);

            $tester = new CommandTester(new ConfigSeedCommand());
            self::assertSame(0, $tester->execute(["--yes" => true]));

            $values = $this->readEnvironment($compose->envFile());
            self::assertSame("operator", $values["PROXYSQL_ADMIN_USER"]);
            self::assertNotSame("", $values["PROXYSQL_ADMIN_PASSWORD"]);
            self::assertSame("proxyweb", $values["PROXYWEB_ADMIN_USER"]);
            self::assertNotSame("", $values["PROXYWEB_ADMIN_PASSWORD"]);
        } finally {
            putenv($previousHome === false ? "HOME" : "HOME=" . $previousHome);
            $this->removeDirectory($temporaryHome);
        }
    }

    /** @return array<string, string> */
    private function readEnvironment(string $file): array
    {
        $values = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (!str_contains($line, "=")) {
                continue;
            }

            [$key, $value] = explode("=", $line, 2);
            $values[$key] = $value;
        }

        return $values;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

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
