<?php

declare(strict_types=1);

namespace DockerCli\Tests\Integration\Resources;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class GlitchTipConfigurationTest extends TestCase
{
    public function testGlitchTipIsIsolatedAndPublishedThroughTraefik(): void
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 3) . "/resources/compose/system/compose.yaml");

        self::assertIsArray($compose);
        $services = $compose["services"] ?? null;
        self::assertIsArray($services);
        foreach (["glitchtip-postgres", "glitchtip-valkey", "glitchtip-init", "glitchtip"] as $service) {
            self::assertSame(["glitchtip"], $services[$service]["profiles"] ?? null);
        }
        self::assertSame("glitchtip/glitchtip:6.2.6", $services["glitchtip"]["image"] ?? null);
        self::assertSame("false", $services["glitchtip"]["environment"]["ENABLE_USER_REGISTRATION"] ?? null);
        self::assertSame(
            'Host(`glitchtip.${BASE_HOST}`)',
            $services["glitchtip"]["labels"]["traefik.http.routers.glitchtip.rule"] ?? null,
        );
        self::assertSame(
            ["glitchtip-uploads:/code/uploads"],
            $services["glitchtip"]["volumes"] ?? null,
        );
        self::assertSame("/code/uploads", $services["glitchtip"]["environment"]["MEDIA_ROOT"] ?? null);
    }

    public function testAdministratorIsSynchronisedFromEnvironmentAfterMigrations(): void
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 3) . "/resources/compose/system/compose.yaml");

        self::assertIsArray($compose);
        $init = $compose["services"]["glitchtip-init"] ?? null;
        self::assertIsArray($init);
        self::assertSame(
            '${GLITCHTIP_ADMIN_EMAIL:?GLITCHTIP_ADMIN_EMAIL is required}',
            $init["environment"]["DJANGO_SUPERUSER_EMAIL"] ?? null,
        );
        self::assertSame(
            '${GLITCHTIP_ADMIN_PASSWORD:?GLITCHTIP_ADMIN_PASSWORD is required}',
            $init["environment"]["DJANGO_SUPERUSER_PASSWORD"] ?? null,
        );
        self::assertStringContainsString("./bin/run-migrate.sh", $init["command"][0] ?? "");
        self::assertStringContainsString("get_or_create(email=email)", $init["command"][0] ?? "");
        self::assertSame(
            ["condition" => "service_completed_successfully"],
            $compose["services"]["glitchtip"]["depends_on"]["glitchtip-init"] ?? null,
        );
    }
}
