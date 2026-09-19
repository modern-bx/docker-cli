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
}
