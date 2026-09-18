<?php

declare(strict_types=1);

namespace DockerCli\Tests\Unit\System;

use DockerCli\System\SudoersManager;
use PHPUnit\Framework\TestCase;

final class SudoersManagerTest extends TestCase
{
    public function testSetupAllowsRemovalCommands(): void
    {
        $directory = sys_get_temp_dir() . "/docker-cli-sudoers-" . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));

        try {
            $file = (new SudoersManager($directory))->setup("docker-cli-test", false);
            $content = file_get_contents($file);

            self::assertIsString($content);
            self::assertStringContainsString("/usr/bin/rm", $content);
            self::assertStringContainsString("/usr/bin/rmdir", $content);
        } finally {
            foreach (glob($directory . "/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
