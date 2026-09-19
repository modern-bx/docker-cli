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
}
