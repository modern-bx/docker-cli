<?php

declare(strict_types=1);

namespace DockerCli\Panel\Enum;

enum ProjectActionEnum: string
{
    public const ROUTE_PATTERN = 'enable|disable|wipe';

    case Enable = 'enable';
    case Disable = 'disable';
    case Wipe = 'wipe';

    public static function isEnable(self|string $action): bool
    {
        return self::value($action) === self::Enable->value;
    }

    public static function isDisable(self|string $action): bool
    {
        return self::value($action) === self::Disable->value;
    }

    public static function isWipe(self|string $action): bool
    {
        return self::value($action) === self::Wipe->value;
    }

    private static function value(self|string $action): string
    {
        return $action instanceof self ? $action->value : $action;
    }
}
