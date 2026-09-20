<?php

declare(strict_types=1);

namespace DockerCli\Tests\Integration\Resources;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ProxyServicesConfigurationTest extends TestCase
{
    public function testComposePublishesBothWebInterfacesAndConnectsProxyWebToProxySql(): void
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 3) . "/resources/compose/system/compose.yaml");

        self::assertIsArray($compose);
        self::assertIsArray($compose["services"] ?? null);
        $services = $compose["services"];
        self::assertSame("proxysql/proxysql:3.0.11", $services["proxysql"]["image"] ?? null);
        self::assertSame("proxyweb/proxyweb:2.3.0", $services["proxyweb"]["image"] ?? null);
        self::assertSame(
            "6032",
            $services["proxyweb"]["environment"]["PROXYWEB_SERVER_PROXYSQL_PORT"] ?? null,
        );
        self::assertSame(
            "proxysql",
            $services["proxyweb"]["environment"]["PROXYWEB_SERVER_PROXYSQL_HOST"] ?? null,
        );
        self::assertSame(
            'Host(`proxysql.${BASE_HOST}`)',
            $services["proxysql"]["labels"]["traefik.http.routers.proxysql.rule"] ?? null,
        );
        self::assertSame(
            'Host(`proxyweb.${BASE_HOST}`)',
            $services["proxyweb"]["labels"]["traefik.http.routers.proxyweb.rule"] ?? null,
        );
        self::assertSame(
            "https",
            $services["proxysql"]["labels"]["traefik.http.services.proxysql.loadbalancer.server.scheme"] ?? null,
        );
        self::assertSame(
            "proxysql-web@file",
            $services["proxysql"]["labels"]["traefik.http.services.proxysql.loadbalancer.serverstransport"]
                ?? null,
        );
        self::assertContains(
            "--providers.file.filename=/etc/traefik/dynamic.yaml",
            $services["traefik"]["command"] ?? [],
        );

        $dynamicConfiguration = Yaml::parseFile(
            dirname(__DIR__, 3) . "/resources/compose/system/config/traefik/dynamic.yaml",
        );
        self::assertIsArray($dynamicConfiguration);
        self::assertTrue(
            $dynamicConfiguration["http"]["serversTransports"]["proxysql-web"]["insecureSkipVerify"] ?? null,
        );
        self::assertArrayNotHasKey(
            "traefik.http.routers.proxysql.middlewares",
            $services["proxysql"]["labels"] ?? [],
        );
        self::assertSame(["proxysql"], $services["proxyweb"]["depends_on"] ?? null);
        self::assertSame(["proxysql"], $services["proxysql"]["profiles"] ?? null);
        self::assertSame(["proxyweb"], $services["proxyweb"]["profiles"] ?? null);
    }

    public function testProxySqlEnablesWebUiAndRegistersSystemDatabases(): void
    {
        $configuration = $this->read("resources/compose/system/config/proxysql/proxysql.cnf.template");

        self::assertStringContainsString("web_enabled=true", $configuration);
        self::assertStringContainsString("web_port=6080", $configuration);
        self::assertStringContainsString(
            'admin_credentials="admin:__PROXYSQL_ADMIN_PASSWORD__;' .
                '__PROXYSQL_ADMIN_USER__:__PROXYSQL_ADMIN_PASSWORD__"',
            $configuration,
        );
        self::assertStringContainsString(
            'stats_credentials="__PROXYSQL_WEB_USER__:__PROXYSQL_WEB_PASSWORD__"',
            $configuration,
        );
        self::assertStringContainsString('address="mysql"', $configuration);
        self::assertStringContainsString("port=3306", $configuration);
        self::assertStringContainsString('address="postgres"', $configuration);
        self::assertStringContainsString("port=5432", $configuration);
        self::assertSame(2, substr_count($configuration, "monitor_enabled=false"));

        $compose = $this->read("resources/compose/system/compose.yaml");
        self::assertStringContainsString("initial=--initial", $compose);
        self::assertStringContainsString(".docker-cli-admin-credentials-v3", $compose);
    }

    public function testProxyWebContainsDefaultProxySqlServer(): void
    {
        $configuration = Yaml::parseFile(
            dirname(__DIR__, 3) . "/resources/compose/system/config/proxyweb/config.yml",
        );

        self::assertIsArray($configuration);
        self::assertSame("proxysql", $configuration["global"]["default_server"] ?? null);
        self::assertSame("proxysql", $configuration["servers"]["proxysql"]["dsn"][0]["host"] ?? null);
        self::assertSame(6032, $configuration["servers"]["proxysql"]["dsn"][0]["port"] ?? null);
        self::assertFalse($configuration["auth"]["okta"]["enabled"] ?? null);
        self::assertSame([], $configuration["misc"]["apply_config"] ?? null);
        self::assertSame([], $configuration["misc"]["update_config"] ?? null);
        self::assertSame([], $configuration["misc"]["adhoc_report"] ?? null);
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . "/" . $relativePath);

        self::assertIsString($contents, sprintf("Не удалось прочитать файл %s.", $relativePath));

        return $contents;
    }
}
