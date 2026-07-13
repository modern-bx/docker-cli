<?php

declare(strict_types=1);

namespace DockerCli\Util;

function join_path(string ...$parts): string
{
    if ($parts === []) {
        return '';
    }

    $prefix = str_starts_with($parts[0], DIRECTORY_SEPARATOR) ? DIRECTORY_SEPARATOR : '';
    $segments = [];

    foreach ($parts as $part) {
        $part = trim($part, DIRECTORY_SEPARATOR);
        if ($part !== '') {
            $segments[] = $part;
        }
    }

    if ($segments === []) {
        return $prefix;
    }

    return $prefix . implode(DIRECTORY_SEPARATOR, $segments);
}
