<?php

declare(strict_types=1);

namespace DockerCli\Tests\Integration\Resources;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DbtrailConfigurationTest extends TestCase
{
    public function testDbtrailUsesOnlyMysqlAndIsPublishedThroughTraefik(): void
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 3) . "/resources/compose/system/compose.yaml");

        self::assertIsArray($compose);
        self::assertIsArray($compose["services"] ?? null);
        $services = $compose["services"];
        self::assertSame("mysql:8.4", $services["dbtrail-index"]["image"] ?? null);
        self::assertSame("ghcr.io/dbtrail/bintrail-console:0.84.0", $services["dbtrail"]["image"] ?? null);
        self::assertSame(
            '${DBTRAIL_MYSQL_USER:?DBTRAIL_MYSQL_USER is required}:' .
                '${DBTRAIL_MYSQL_PASSWORD:?DBTRAIL_MYSQL_PASSWORD is required}@tcp(mysql:3306)/',
            $services["dbtrail"]["environment"]["SOURCE_DSN"] ?? null,
        );
        self::assertSame(
            'root:${DBTRAIL_INDEX_PASSWORD:?DBTRAIL_INDEX_PASSWORD is required}' .
                '@tcp(dbtrail-index:3306)/bintrail_index',
            $services["dbtrail"]["environment"]["INDEX_DSN"] ?? null,
        );
        self::assertSame(
            'Host(`dbtrail.${BASE_HOST}`)',
            $services["dbtrail"]["labels"]["traefik.http.routers.dbtrail.rule"] ?? null,
        );
        self::assertSame(
            'dbtrail.${BASE_HOST}',
            $services["dbtrail"]["environment"]["BINTRAIL_CONSOLE_ALLOWED_HOSTS"] ?? null,
        );
        self::assertSame(
            "system-http-auth",
            $services["dbtrail"]["labels"]["traefik.http.routers.dbtrail.middlewares"] ?? null,
        );
        self::assertSame(
            ['exec bintrail-console watch --source-dsn "$${SOURCE_DSN}" --index-dsn "$${INDEX_DSN}"'],
            $services["dbtrail"]["command"] ?? null,
        );
        self::assertStringNotContainsString("postgres", serialize($services["dbtrail"]));
    }

    public function testMysqlHasTheBinaryLogSettingsRequiredByDbtrail(): void
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 3) . "/resources/compose/system/compose.yaml");

        self::assertIsArray($compose);
        $command = $compose["services"]["mysql"]["command"] ?? null;
        self::assertIsArray($command);
        self::assertContains("--server-id=1", $command);
        self::assertContains("--log-bin=mysql-bin", $command);
        self::assertContains("--binlog-format=ROW", $command);
        self::assertContains("--binlog-row-image=FULL", $command);
        self::assertContains("--binlog-row-value-options=", $command);
    }

    public function testDbtrailReplicationUserIsProvisionedFromEnvironment(): void
    {
        $composeFile = dirname(__DIR__, 3) . "/resources/compose/system/compose.yaml";
        $compose = file_get_contents($composeFile);

        self::assertIsString($compose);
        self::assertStringContainsString("REPLICATION SLAVE, REPLICATION CLIENT", $compose);
        self::assertStringContainsString('$${DBTRAIL_MYSQL_USER}', $compose);
        self::assertStringContainsString('$${DBTRAIL_MYSQL_PASSWORD}', $compose);

        $configuration = Yaml::parseFile($composeFile);
        self::assertIsArray($configuration);
        self::assertSame(
            ["dbtrail-mysql-socket:/var/run/mysqld"],
            $configuration["services"]["dbtrail-mysql-init"]["volumes"] ?? null,
        );
        self::assertContains(
            "dbtrail-mysql-socket:/var/run/mysqld",
            $configuration["services"]["mysql"]["volumes"] ?? [],
        );
        self::assertStringContainsString("--protocol=socket", $compose);
        self::assertStringNotContainsString("mysql -h mysql -u root", $compose);
    }
}
