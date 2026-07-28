<?php

declare(strict_types=1);

namespace DockerCli\Project;

final class DatabasePasswordGenerator
{
    public function generate(): string
    {
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $digits = '0123456789';
        $alphabet = $lowercase . $uppercase . $digits;
        $characters = [
            $lowercase[random_int(0, strlen($lowercase) - 1)],
            $uppercase[random_int(0, strlen($uppercase) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
        ];

        for ($i = count($characters); $i < 24; $i++) {
            $characters[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        for ($i = count($characters) - 1; $i > 0; $i--) {
            $position = random_int(0, $i);
            [$characters[$i], $characters[$position]] = [$characters[$position], $characters[$i]];
        }

        return implode('', $characters);
    }
}
