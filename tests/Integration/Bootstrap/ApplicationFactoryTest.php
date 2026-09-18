<?php

declare(strict_types=1);

namespace DockerCli\Tests\Integration\Bootstrap;

use DockerCli\Bootstrap\ApplicationFactory;
use PHPUnit\Framework\TestCase;

final class ApplicationFactoryTest extends TestCase
{
    public function testCreatesConfiguredApplication(): void
    {
        $application = ApplicationFactory::createDefault()->create();

        self::assertSame(ApplicationFactory::APPLICATION_NAME, $application->getName());
        self::assertSame(ApplicationFactory::APPLICATION_VERSION, $application->getVersion());
        self::assertTrue($application->has("shell:run"));
        self::assertTrue($application->has("project:up"));
    }
}
