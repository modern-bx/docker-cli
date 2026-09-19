<?php

declare(strict_types=1);

namespace DockerCli\Tests\Integration\Command;

use DockerCli\Command\TaskRunCommand;
use DockerCli\Task\TaskRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TaskRunCommandTest extends TestCase
{
    public function testAcceptsProjectInitTagWithColon(): void
    {
        $repository = new TaskRepository(dirname(__DIR__, 3) . "/resources/tasks/core");
        $tester = new CommandTester(new TaskRunCommand($repository));

        $exitCode = $tester->execute(["task-code" => "core.project.init.bitrix"]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Обязательный параметр "edition" не передан.', $tester->getDisplay());
        self::assertStringNotContainsString("Некорректный тег задачи", $tester->getDisplay());
    }
}
