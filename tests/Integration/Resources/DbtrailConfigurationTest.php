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
        self::assertSame("dbtrail/Dockerfile", $services["dbtrail"]["build"]["dockerfile"] ?? null);
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
            'Host(`dbtrail.${BASE_HOST}`) && PathPrefix(`/api`)',
            $services["dbtrail"]["labels"]["traefik.http.routers.dbtrail-api.rule"] ?? null,
        );
        self::assertSame(
            "dbtrail",
            $services["dbtrail"]["labels"]["traefik.http.routers.dbtrail-api.service"] ?? null,
        );
        self::assertSame(
            "100",
            $services["dbtrail"]["labels"]["traefik.http.routers.dbtrail-api.priority"] ?? null,
        );
        self::assertSame(
            "dbtrail",
            $services["dbtrail"]["labels"]["traefik.http.routers.dbtrail.service"] ?? null,
        );
        self::assertSame(
            'dbtrail,dbtrail.${BASE_HOST}',
            $services["dbtrail"]["environment"]["BINTRAIL_CONSOLE_ALLOWED_HOSTS"] ?? null,
        );
        self::assertSame(
            '${DBTRAIL_CONSOLE_TOKEN:?DBTRAIL_CONSOLE_TOKEN is required}',
            $services["dbtrail"]["environment"]["BINTRAIL_CONSOLE_TOKEN"] ?? null,
        );
        self::assertSame(
            "system-http-auth",
            $services["dbtrail"]["labels"]["traefik.http.routers.dbtrail.middlewares"] ?? null,
        );
        self::assertSame(["./data/dbtrail:/var/lib/bintrail"], $services["dbtrail"]["volumes"] ?? null);
        self::assertSame(["./data/dbtrail-index:/var/lib/mysql"], $services["dbtrail-index"]["volumes"] ?? null);
        self::assertSame(
            ['exec bintrail-console watch --source-dsn "$${SOURCE_DSN}" --index-dsn "$${INDEX_DSN}"'],
            $services["dbtrail"]["command"] ?? null,
        );
        self::assertStringNotContainsString("postgres", serialize($services["dbtrail"]));
        foreach (["dbtrail-mysql-init", "dbtrail-index", "dbtrail", "dbtrail-sync"] as $service) {
            self::assertSame(["dbtrail"], $services[$service]["profiles"] ?? null);
        }
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
        self::assertContains("--binlog-row-metadata=FULL", $command);
        self::assertContains("--binlog-rows-query-log-events=ON", $command);
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

    public function testDbtrailImageUsesDegradedInitialSnapshot(): void
    {
        $dockerfile = $this->read("resources/compose/system/config/dbtrail/Dockerfile");

        self::assertStringContainsString("DBTRAIL_VERSION=0.84.0", $dockerfile);
        self::assertStringContainsString("DBTRAIL_ARCHIVE_SHA256=", $dockerfile);
        self::assertStringContainsString(
            "TakeSnapshotExcludingInvalid(sourceDB, indexDB, schemas)",
            $dockerfile,
        );
        self::assertStringContainsString('return !s.passwordLoginEnabled()', $dockerfile);
        self::assertStringNotContainsString("--tables", $dockerfile);
    }

    public function testDedicatedMysqlSynchronizerReconcilesTheCompleteContainerList(): void
    {
        $script = $this->read("resources/compose/system/config/dbtrail-sync/sync.sh");
        $dockerfile = $this->read("resources/compose/system/config/dbtrail-sync/Dockerfile");

        self::assertStringContainsString("RUN chmod 0755 /usr/local/bin/docker-cli-dbtrail-sync", $dockerfile);
        self::assertStringContainsString(
            'ENTRYPOINT ["/usr/local/bin/docker-cli-dbtrail-sync"]',
            $dockerfile,
        );

        $compose = Yaml::parseFile(dirname(__DIR__, 3) . "/resources/compose/system/compose.yaml");
        self::assertIsArray($compose);
        self::assertSame(
            ["/usr/local/bin/docker-cli-dbtrail-sync"],
            $compose["services"]["dbtrail-sync"]["entrypoint"] ?? null,
        );

        self::assertStringContainsString("docker ps --all --filter label=docker-cli.dbtrail-source=mysql", $script);
        self::assertStringContainsString('curl --fail --silent "$api/healthz"', $script);
        self::assertStringContainsString("request POST /servers", $script);
        self::assertStringContainsString('request POST "/servers/$id/monitor/start"', $script);
        self::assertStringContainsString("missing_limit=3", $script);
        self::assertStringContainsString('if [ "$misses" -lt "$missing_limit" ]', $script);
        self::assertStringContainsString('request POST "/servers/$id/monitor/stop"', $script);
        self::assertStringContainsString('request DELETE "/servers/$id"', $script);
        self::assertStringContainsString("sleep 60", $script);

        $renderer = $this->read("src/Project/DedicatedDatabaseComposeRenderer.php");
        self::assertStringContainsString('"docker-cli.dbtrail-source" => "mysql"', $renderer);
        self::assertStringContainsString('"--binlog-format=ROW"', $renderer);
        self::assertStringContainsString('"--binlog-row-image=FULL"', $renderer);
        self::assertStringContainsString('"--binlog-row-metadata=FULL"', $renderer);
        self::assertStringContainsString('"--binlog-rows-query-log-events=ON"', $renderer);
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . "/" . $relativePath);

        self::assertIsString($contents, sprintf("Не удалось прочитать файл %s.", $relativePath));

        return $contents;
    }
}
