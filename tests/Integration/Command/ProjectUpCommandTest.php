<?php

declare(strict_types=1);

namespace DockerCli\Tests\Integration\Command;

use DockerCli\Command\ProjectUpCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ProjectUpCommandTest extends TestCase
{
    public function testRejectsUnsupportedLanguageVersion(): void
    {
        $tester = new CommandTester(new ProjectUpCommand());

        $exitCode = $tester->execute(["--language" => "php", "--language-version" => "7.4"]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString("Указан неподдерживаемый язык или фреймворк.", $tester->getDisplay());
    }

    public function testRejectsExternalPortWithoutExternalFlag(): void
    {
        $tester = new CommandTester(new ProjectUpCommand());

        $exitCode = $tester->execute(["--framework" => "symfony", "--external-port" => "8081"]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString("только с --external", $tester->getDisplay());
    }

    public function testRejectsInvalidExternalPort(): void
    {
        $tester = new CommandTester(new ProjectUpCommand());

        $exitCode = $tester->execute(["--external" => true, "--external-port" => "70000"]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString("от 1 до 65535", $tester->getDisplay());
    }
}
