<?php

declare(strict_types=1);

namespace DockerCli\Tests\Unit\Panel\Dto\Request;

use DockerCli\Panel\Dto\Request\ProjectCreateRequestDto;
use DockerCli\Panel\Http\RequestData;
use PHPUnit\Framework\TestCase;

final class ProjectCreateRequestDtoTest extends TestCase
{
    public function testReadsLanguageVersion(): void
    {
        $request = new RequestData(
            [],
            ["location" => "default", "language" => "php", "languageVersion" => "8.4"],
            [],
            null,
            null,
        );

        $dto = ProjectCreateRequestDto::fromRequest($request);

        self::assertSame("8.4", $dto->languageVersion);
    }
}
