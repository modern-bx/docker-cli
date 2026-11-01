<?php

declare(strict_types=1);

namespace DockerCli\Tests\Integration\Resources;

use PHPUnit\Framework\TestCase;

final class PlaywrightScenarioTest extends TestCase
{
    public function testBitrix24TitlesUseTheSameInstallationScenario(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . "/resources/playwright/scripts/bitrix/setup.js");

        self::assertIsString($script);
        self::assertStringContainsString('Установка «1С-Битрикс24: Корпоративный портал»', $script);
        self::assertStringContainsString('Установка «1С-Битрикс24: Энтерпрайз»', $script);
        self::assertStringContainsString("BITRIX24_TITLES.has(normalizeTitle(title))", $script);
    }
}
