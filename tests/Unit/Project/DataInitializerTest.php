<?php

declare(strict_types=1);

namespace DockerCli\Tests\Unit\Project;

use DockerCli\Project\DataInitializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\NullOutput;

final class DataInitializerTest extends TestCase
{
    public function testDropSucceedsWithoutConnectingToExcludedDatabases(): void
    {
        $initializer = new DataInitializer();

        self::assertSame(Command::SUCCESS, $initializer->drop("example", new NullOutput(), []));
    }
}
