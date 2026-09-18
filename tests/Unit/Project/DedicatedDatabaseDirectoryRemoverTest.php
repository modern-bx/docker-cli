<?php

declare(strict_types=1);

namespace DockerCli\Tests\Unit\Project;

use DockerCli\Project\DedicatedDatabaseDirectoryRemover;
use PHPUnit\Framework\TestCase;

final class DedicatedDatabaseDirectoryRemoverTest extends TestCase
{
    public function testRemovesDataAndInstanceDirectoriesUsingSudo(): void
    {
        $commands = [];
        $remover = new DedicatedDatabaseDirectoryRemover(
            static function (array $command) use (&$commands): int {
                $commands[] = $command;

                return 0;
            },
        );
        $location = sys_get_temp_dir() . "/docker-cli-dedicated-test";

        self::assertTrue($remover->remove(
            "example",
            ["mysql"],
            ["data" => ["databases" => ["mysql" => ["location" => $location]]]],
        ));
        self::assertSame(
            [
                ["sudo", "rm", "-rf", "--", $location . "/data"],
                ["sudo", "rmdir", "--", $location],
            ],
            $commands,
        );
    }

    public function testStopsAfterFailedRemoval(): void
    {
        $commands = [];
        $remover = new DedicatedDatabaseDirectoryRemover(
            static function (array $command) use (&$commands): int {
                $commands[] = $command;

                return 1;
            },
        );

        self::assertFalse($remover->remove(
            "example",
            ["postgres"],
            ["data" => ["databases" => ["postgres" => ["location" => "/srv/postgres-example"]]]],
        ));
        self::assertSame([["sudo", "rm", "-rf", "--", "/srv/postgres-example/data"]], $commands);
    }
}
