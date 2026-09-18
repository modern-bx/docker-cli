<?php

declare(strict_types=1);

namespace DockerCli\Tests\Unit\Bootstrap;

use DockerCli\Bootstrap\ArgvNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArgvNormalizerTest extends TestCase {
    /**
     * @param list<string> $arguments
     * @param list<string> $expected
     */
    #[DataProvider("argumentsProvider")]
    public function testNormalize(array $arguments, array $expected): void {
        self::assertSame($expected, (new ArgvNormalizer())->normalize($arguments));
    }

    /** @return iterable<string, array{list<string>, list<string>}> */
    public static function argumentsProvider(): iterable {
        yield "внешняя команда" => [
            ["docker-cli", "--project=example", "--", "php", "-v"],
            ["docker-cli", "shell:run", "--project=example", "--", "php", "-v"],
        ];
        yield "явная команда" => [
            ["docker-cli", "project:list", "--", "ignored"],
            ["docker-cli", "project:list", "--", "ignored"],
        ];
        yield "без разделителя" => [["docker-cli"], ["docker-cli"]];
        yield "разделитель первым аргументом" => [["--", "php"], ["--", "php"]];
    }
}
