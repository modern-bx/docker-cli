<?php

declare(strict_types=1);

namespace DockerCli\Tests\Unit\Project;

use DockerCli\Project\ProjectRegistry;
use DockerCli\Project\ProxySqlConfigurationSynchronizer;
use PHPUnit\Framework\TestCase;

final class ProxySqlConfigurationSynchronizerTest extends TestCase
{
    public function testBuildsServersUsersAndDatabaseRoutesForSharedAndDedicatedInstances(): void
    {
        $previousHome = getenv("HOME");
        $home = sys_get_temp_dir() . "/docker-cli-proxysql-sync-" . bin2hex(random_bytes(8));
        mkdir($home, 0755, true);
        putenv("HOME=" . $home);

        try {
            $registry = new ProjectRegistry();
            $this->writeProject($registry, "alpha", "docker-cli-mysql", "docker-cli-postgres");
            $this->writeProject($registry, "beta", "docker-cli-mysql-beta", "docker-cli-postgres-beta");

            $sql = (new ProxySqlConfigurationSynchronizer($registry))->sql();

            self::assertStringContainsString("'docker-cli-mysql', 3306, 'docker-cli'", $sql);
            self::assertStringContainsString("'docker-cli-mysql-beta', 3306, 'docker-cli'", $sql);
            self::assertStringContainsString("'docker-cli-postgres', 5432, 'docker-cli'", $sql);
            self::assertStringContainsString("'docker-cli-postgres-beta', 5432, 'docker-cli'", $sql);
            self::assertStringContainsString("username, schemaname, destination_hostgroup", $sql);
            self::assertStringContainsString("username, database, destination_hostgroup", $sql);
            self::assertStringContainsString("'alpha', 'alpha'", $sql);
            self::assertStringContainsString("'beta', 'beta'", $sql);
            self::assertStringContainsString("LOAD MYSQL QUERY RULES TO RUNTIME", $sql);
            self::assertStringContainsString("LOAD PGSQL QUERY RULES TO RUNTIME", $sql);
        } finally {
            putenv($previousHome === false ? "HOME" : "HOME=" . $previousHome);
            $this->removeDirectory($home);
        }
    }

    private function writeProject(ProjectRegistry $registry, string $name, string $mysql, string $postgres): void
    {
        mkdir($registry->projectDirectory($name), 0755, true);
        $registry->writeProjectConfig($name, [
            "data" => [
                "databases" => [
                    "mysql" => [
                        "hostname" => $mysql,
                        "username" => $name,
                        "database" => $name,
                        "password" => "mysql-'$name",
                    ],
                    "postgres" => [
                        "hostname" => $postgres,
                        "username" => $name,
                        "database" => $name,
                        "password" => "postgres-$name",
                    ],
                ],
            ],
        ]);
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
