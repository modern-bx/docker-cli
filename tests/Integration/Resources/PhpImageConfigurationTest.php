<?php

declare(strict_types=1);

namespace DockerCli\Tests\Integration\Resources;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhpImageConfigurationTest extends TestCase
{
    private const PHP_VERSIONS = ["8.2", "8.3", "8.4", "8.5"];

    public function testSystemEnvironmentEnablesProfilersByDefault(): void
    {
        $environment = $this->read("resources/compose/system/.env");

        self::assertStringContainsString("PHP_ENABLE_XDEBUG=1", $environment);
        self::assertStringContainsString("PHP_ENABLE_SPX=1", $environment);
    }

    public function testComposePassesCommonFlagsToEveryPhpBuild(): void
    {
        $compose = $this->read("resources/compose/system/compose.yaml");

        self::assertSame(4, substr_count($compose, 'PHP_ENABLE_XDEBUG: ${PHP_ENABLE_XDEBUG:-1}'));
        self::assertSame(4, substr_count($compose, 'PHP_ENABLE_SPX: ${PHP_ENABLE_SPX:-1}'));
    }

    #[DataProvider("phpVersions")]
    public function testPhpImageSupportsConditionalXdebugAndSpx(string $version): void
    {
        $base = sprintf("resources/compose/system/config/php-fpm-%s", $version);
        $dockerfile = $this->read($base . "/Dockerfile");
        $installer = $this->read($base . "/scripts/install-extensions.sh");
        $spxConfiguration = $this->read($base . "/php/conf.d/zz-spx.ini");
        $fpmConfiguration = $this->read($base . "/php-fpm.d/zz-local.conf");

        self::assertStringContainsString("ARG PHP_ENABLE_XDEBUG=1", $dockerfile);
        self::assertStringContainsString("ARG PHP_ENABLE_SPX=1", $dockerfile);
        self::assertStringContainsString('${PHP_ENABLE_XDEBUG:-1}', $installer);
        self::assertStringContainsString('${PHP_ENABLE_SPX:-1}', $installer);
        self::assertStringContainsString("php-spx-0.4.22", $installer);
        self::assertStringContainsString("spx.http_enabled=1", $spxConfiguration);
        self::assertStringContainsString("process.dumpable = yes", $fpmConfiguration);
    }

    /** @return iterable<string, array{string}> */
    public static function phpVersions(): iterable
    {
        foreach (self::PHP_VERSIONS as $version) {
            yield "PHP " . $version => [$version];
        }
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . "/" . $relativePath);

        self::assertIsString($contents, sprintf("Не удалось прочитать файл %s.", $relativePath));

        return $contents;
    }
}
