<?php

declare(strict_types=1);

namespace DockerCli\Tests\Integration\Resources;

use DockerCli\Config\SystemCompose;
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
        self::assertSame(4, substr_count($compose, "context: ./config\n"));
    }

    public function testConfigInitExportsSpxStylesWithoutOverwritingUserChanges(): void
    {
        $previousHome = getenv("HOME");
        $temporaryHome = sys_get_temp_dir() . "/docker-cli-spx-" . bin2hex(random_bytes(8));
        mkdir($temporaryHome, 0755, true);
        putenv("HOME=" . $temporaryHome);

        try {
            $compose = new SystemCompose();
            $compose->init();
            $settingsFile = $compose->directory() . "/config/php-spx/settings.css";

            self::assertFileExists($settingsFile);
            self::assertStringContainsString("form#config", (string) file_get_contents($settingsFile));

            file_put_contents($settingsFile, "/* Пользовательские стили. */\n");
            $compose->init(updateStatic: true);

            self::assertSame("/* Пользовательские стили. */\n", file_get_contents($settingsFile));
        } finally {
            putenv($previousHome === false ? "HOME" : "HOME=" . $previousHome);
            $this->removeDirectory($temporaryHome);
        }
    }

    public function testLaravelOpenRestyTemplateUsesFrontController(): void
    {
        $configuration = $this->read("resources/compose/system/config/openresty/hosts/laravel/web.conf");

        self::assertStringContainsString("index index.php index.html index.htm;", $configuration);
        self::assertStringContainsString('try_files $uri $uri/ /index.php$is_args$args;', $configuration);
    }

    #[DataProvider("phpVersions")]
    public function testPhpImageSupportsConditionalXdebugAndSpx(string $version): void
    {
        $base = sprintf("resources/compose/system/config/php-fpm-%s", $version);
        $dockerfile = $this->read($base . "/Dockerfile");
        $installer = $this->read($base . "/scripts/install-extensions.sh");
        $webUiPatch = $this->read($base . "/spx-web-ui.patch");
        $spxConfiguration = $this->read($base . "/php/conf.d/zz-spx.ini");
        $fpmConfiguration = $this->read($base . "/php-fpm.d/zz-local.conf");

        self::assertStringContainsString("ARG PHP_ENABLE_XDEBUG=1", $dockerfile);
        self::assertStringContainsString("ARG PHP_ENABLE_SPX=1", $dockerfile);
        self::assertStringContainsString(
            "COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer",
            $dockerfile,
        );
        self::assertStringContainsString('${PHP_ENABLE_XDEBUG:-1}', $installer);
        self::assertStringContainsString('${PHP_ENABLE_SPX:-1}', $installer);
        self::assertStringContainsString("php-spx-0.4.22", $installer);
        self::assertStringContainsString("patch -p1 < /usr/local/src/spx-web-ui.patch", $installer);
        self::assertStringContainsString("cat /usr/local/src/spx-settings.css", $installer);
        self::assertStringContainsString("if (frame === undefined)", $webUiPatch);
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
