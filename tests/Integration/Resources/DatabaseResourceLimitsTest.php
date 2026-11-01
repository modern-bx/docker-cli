<?php

declare(strict_types=1);

namespace DockerCli\Tests\Integration\Resources;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DatabaseResourceLimitsTest extends TestCase
{
    public function testLowLoadDatabaseContainersHaveConfigurableResourceLimits(): void
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 3) . "/resources/compose/system/compose.yaml");

        self::assertIsArray($compose);
        $services = $compose["services"] ?? null;
        self::assertIsArray($services);
        self::assertSame('${MYSQL_CPU_LIMIT:-1.0}', $services["mysql"]["cpus"] ?? null);
        self::assertSame('${MYSQL_MEMORY_LIMIT:-1G}', $services["mysql"]["mem_limit"] ?? null);
        self::assertSame('${POSTGRES_CPU_LIMIT:-0.5}', $services["postgres"]["cpus"] ?? null);
        self::assertSame('${POSTGRES_MEMORY_LIMIT:-512M}', $services["postgres"]["mem_limit"] ?? null);
        self::assertSame(
            '${GLITCHTIP_POSTGRES_CPU_LIMIT:-0.5}',
            $services["glitchtip-postgres"]["cpus"] ?? null,
        );
        self::assertSame(
            '${GLITCHTIP_POSTGRES_MEMORY_LIMIT:-512M}',
            $services["glitchtip-postgres"]["mem_limit"] ?? null,
        );
    }

    public function testEnvironmentTemplateDocumentsResourceLimitDefaults(): void
    {
        $environment = file_get_contents(dirname(__DIR__, 3) . "/resources/compose/system/.env");

        self::assertIsString($environment);
        self::assertStringContainsString("MYSQL_CPU_LIMIT=1.0\n", $environment);
        self::assertStringContainsString("MYSQL_MEMORY_LIMIT=1G\n", $environment);
        self::assertStringContainsString("POSTGRES_CPU_LIMIT=0.5\n", $environment);
        self::assertStringContainsString("POSTGRES_MEMORY_LIMIT=512M\n", $environment);
        self::assertStringContainsString("GLITCHTIP_POSTGRES_CPU_LIMIT=0.5\n", $environment);
        self::assertStringContainsString("GLITCHTIP_POSTGRES_MEMORY_LIMIT=512M\n", $environment);
    }
}
