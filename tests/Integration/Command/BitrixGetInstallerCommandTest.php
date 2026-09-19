<?php

declare(strict_types=1);

namespace DockerCli\Tests\Integration\Command;

use DockerCli\Command\BitrixGetInstallerCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class BitrixGetInstallerCommandTest extends TestCase
{
    public function testDefaultPathResolvesToCurrentDirectory(): void
    {
        $directory = sys_get_temp_dir() . "/docker-cli-bitrix-installer-" . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        self::assertTrue(touch($directory . "/standard_encode_php5.tar.gz"));
        $previousDirectory = getcwd();

        try {
            self::assertTrue(chdir($directory));
            $tester = new CommandTester(new BitrixGetInstallerCommand());

            $exitCode = $tester->execute(["--edition" => "standard"]);

            self::assertSame(1, $exitCode);
            self::assertStringContainsString(
                "Файл уже существует: " . $directory . "/standard_encode_php5.tar.gz",
                $tester->getDisplay(),
            );
        } finally {
            if (is_string($previousDirectory)) {
                chdir($previousDirectory);
            }
            unlink($directory . "/standard_encode_php5.tar.gz");
            rmdir($directory);
        }
    }
}
