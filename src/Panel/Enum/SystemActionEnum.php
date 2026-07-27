<?php

declare(strict_types=1);

namespace DockerCli\Panel\Enum;

enum SystemActionEnum: string
{
    public const ROUTE_PATTERN = 'start|stop|restart';

    case Start = 'start';
    case Stop = 'stop';
    case Restart = 'restart';

    public static function isStart(self|string $action): bool
    {
        return self::value($action) === self::Start->value;
    }

    public static function isStop(self|string $action): bool
    {
        return self::value($action) === self::Stop->value;
    }

    public static function isRestart(self|string $action): bool
    {
        return self::value($action) === self::Restart->value;
    }

    private static function value(self|string $action): string
    {
        return $action instanceof self ? $action->value : $action;
    }
}
