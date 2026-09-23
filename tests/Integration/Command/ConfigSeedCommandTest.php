<?php

declare(strict_types=1);

namespace DockerCli\Tests\Integration\Command;

use DockerCli\Command\ConfigSeedCommand;
use DockerCli\Config\SystemCompose;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ConfigSeedCommandTest extends TestCase
{
    public function testSeedsSystemServiceCredentialsAndPreservesExistingValues(): void
    {
        $previousHome = getenv("HOME");
        $temporaryHome = sys_get_temp_dir() . "/docker-cli-config-seed-" . bin2hex(random_bytes(8));
        mkdir($temporaryHome, 0755, true);
        putenv("HOME=" . $temporaryHome);

        try {
            $compose = new SystemCompose();
            $compose->init();
            foreach (
                [
                    "dbtrail",
                    "dbtrail-index",
                    "glitchtip-postgres",
                    "glitchtip-uploads",
                    "glitchtip-valkey",
                    "traefik-letsencrypt",
                ] as $dataDirectory
            ) {
                self::assertDirectoryExists($compose->directory() . "/data/" . $dataDirectory);
            }
            $environment = (string) file_get_contents($compose->envFile());
            $environment = str_replace(
                ["PROXYSQL_ADMIN_USER=", "GLITCHTIP_ADMIN_EMAIL=admin@example.com"],
                ["PROXYSQL_ADMIN_USER=operator", "GLITCHTIP_ADMIN_EMAIL=admin@localhost"],
                $environment,
            );
            file_put_contents($compose->envFile(), $environment);

            $tester = new CommandTester(new ConfigSeedCommand());
            self::assertSame(0, $tester->execute(["--yes" => true]));

            $values = $this->readEnvironment($compose->envFile());
            self::assertSame("1", $values["COMPOSE_ENABLE_PROXYSQL"]);
            self::assertSame("1", $values["COMPOSE_ENABLE_PROXYWEB"]);
            self::assertSame("1", $values["COMPOSE_ENABLE_DBTRAIL"]);
            self::assertSame("1", $values["COMPOSE_ENABLE_GLITCHTIP"]);
            self::assertSame("operator", $values["PROXYSQL_ADMIN_USER"]);
            self::assertNotSame("", $values["PROXYSQL_ADMIN_PASSWORD"]);
            self::assertSame("proxysql-web", $values["PROXYSQL_WEB_USER"]);
            self::assertNotSame("", $values["PROXYSQL_WEB_PASSWORD"]);
            self::assertSame("proxyweb", $values["PROXYWEB_ADMIN_USER"]);
            self::assertNotSame("", $values["PROXYWEB_ADMIN_PASSWORD"]);
            self::assertSame("dbtrail", $values["DBTRAIL_MYSQL_USER"]);
            self::assertNotSame("", $values["DBTRAIL_MYSQL_PASSWORD"]);
            self::assertNotSame("", $values["DBTRAIL_INDEX_PASSWORD"]);
            self::assertNotSame("", $values["DBTRAIL_CONSOLE_TOKEN"]);
            self::assertNotSame("", $values["GLITCHTIP_SECRET_KEY"]);
            self::assertNotSame("", $values["GLITCHTIP_POSTGRES_PASSWORD"]);
            self::assertSame("admin@example.com", $values["GLITCHTIP_ADMIN_EMAIL"]);
            self::assertNotSame("", $values["GLITCHTIP_ADMIN_PASSWORD"]);
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
