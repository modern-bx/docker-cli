<?php

declare(strict_types=1);

namespace DockerCli\Panel;

final class PanelPasswordGenerator
{
    public const LENGTH = 20;
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    public function generate(): string
    {
        $password = '';
        $maximum = strlen(self::ALPHABET) - 1;
        for ($index = 0; $index < self::LENGTH; ++$index) {
            $password .= self::ALPHABET[random_int(0, $maximum)];
        }
        return $password;
    }
}
