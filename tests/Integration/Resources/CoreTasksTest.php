<?php

declare(strict_types=1);

namespace DockerCli\Tests\Integration\Resources;

use DockerCli\Task\TaskRepository;
use PHPUnit\Framework\TestCase;

final class CoreTasksTest extends TestCase
{
    public function testBitrixProjectInitializationTask(): void
    {
        $repository = new TaskRepository(dirname(__DIR__, 3) . "/resources/tasks/core");

        $task = $repository->find("core.project.init.bitrix")["task"];

        self::assertSame("project", $task["context"]);
        self::assertContains("project:init", $task["tags"]);
        self::assertSame(
            ["start", "standard", "small_business", "expert", "business"],
            array_column($task["parameters"]["edition"]["items"], "value"),
        );
        self::assertSame("list", $task["parameters"]["edition"]["type"]);
        self::assertStringContainsString(
            'bitrix:get-installer --product=bitrix --edition="$edition" --extract',
            $task["action"],
        );
        self::assertStringContainsString("docker-cli play:run bitrix/setup", $task["action"]);
    }

    public function testBitrix24ProjectInitializationTask(): void
    {
        $repository = new TaskRepository(dirname(__DIR__, 3) . "/resources/tasks/core");

        $task = $repository->find("core.project.init.bitrix24")["task"];

        self::assertSame("project", $task["context"]);
        self::assertContains("project:init", $task["tags"]);
        self::assertSame(
            ["business", "enterprise", "enterprise_postgresql"],
            array_column($task["parameters"]["edition"]["items"], "value"),
        );
        self::assertStringContainsString(
            'bitrix:get-installer --product=bitrix24 --edition="$edition" --extract',
            $task["action"],
        );
        self::assertStringContainsString("docker-cli play:run bitrix/setup", $task["action"]);
    }

    public function testProjectUpTaskPassesLanguageVersion(): void
    {
        $repository = new TaskRepository(dirname(__DIR__, 3) . "/resources/tasks/core");

        $task = $repository->find("core.project.up")["task"];

        self::assertSame(
            ["8.2", "8.3", "8.4", "8.5"],
            array_column($task["parameters"]["language-version"]["items"], "value"),
        );
        self::assertStringContainsString("--language-version={{ language-version }}", $task["action"]);
    }
}
