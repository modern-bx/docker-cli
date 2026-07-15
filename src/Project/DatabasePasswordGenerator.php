<?php

declare(strict_types=1);

namespace DockerCli\Project;

final class DatabasePasswordGenerator
{
    public function generate(): string
    {
        $letters = 'abcdefghijklmnopqrstuvwxyz';
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $password = $letters[random_int(0, strlen($letters) - 1)];

        for ($i = 1; $i < 24; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }
}
