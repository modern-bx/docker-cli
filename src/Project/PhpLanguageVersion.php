<?php

declare(strict_types=1);

namespace DockerCli\Project;

use DockerCli\Config\SystemCompose;

final class PhpLanguageVersion
{
    public const DEFAULT = '8.2';
    public const SUPPORTED = ['8.2', '8.3', '8.4', '8.5'];

    public static function default(?SystemCompose $compose = null): string
    {
        $version = ($compose ?? new SystemCompose())->envValue('PHP_DEFAULT_VERSION', self::DEFAULT);

        return self::isSupported($version) ? $version : self::DEFAULT;
    }

    public static function isSupported(mixed $version): bool
    {
        return is_string($version) && in_array($version, self::SUPPORTED, true);
    }
}
