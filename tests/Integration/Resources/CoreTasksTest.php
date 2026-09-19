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
        self::assertStringEndsWith(
            "rm -rf -- bitrix/cache bitrix/managed_cache bitrix/stack_cache\n",
            $task["action"],
        );
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
        self::assertStringEndsWith(
            "rm -rf -- bitrix/cache bitrix/managed_cache bitrix/stack_cache\n",
            $task["action"],
        );
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

    public function testLaravelProjectInitializationTask(): void
    {
        $repository = new TaskRepository(dirname(__DIR__, 3) . "/resources/tasks/core");

        $task = $repository->find("core.project.init.laravel")["task"];

        self::assertSame("Установка Laravel", $task["name"]);
        self::assertSame("project", $task["context"]);
        self::assertContains("project:init", $task["tags"]);
        self::assertArrayNotHasKey("parameters", $task);
        self::assertStringContainsString("if ! command -v laravel", $task["action"]);
        self::assertStringContainsString("composer global require laravel/installer --no-interaction", $task["action"]);
        self::assertStringContainsString("composer global config bin-dir --absolute", $task["action"]);
        self::assertStringContainsString("laravel new", $task["action"]);
        self::assertStringContainsString("DB_CONNECTION=mysql", $task["action"]);
        self::assertStringContainsString("databases.mysql.password", $task["action"]);
        self::assertStringEndsWith(
            'docker-cli shell:run --project="$project" ' . "'php artisan migrate --force --no-interaction'\n",
            $task["action"],
        );
    }
}
