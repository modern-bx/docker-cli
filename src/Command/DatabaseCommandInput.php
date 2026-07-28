<?php

declare(strict_types=1);

namespace DockerCli\Command;

trait DatabaseCommandInput
{
    /** @return list<string> */
    private function commaList(mixed $value): array
    {
        if (!is_string($value)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== '')));
    }

    /** @return list<string>|null */
    private function selectedDbms(mixed $value): ?array
    {
        if ($value === null) {
            return \DockerCli\Project\DatabaseManager::DBMS;
        }
        return is_string($value) && in_array($value, \DockerCli\Project\DatabaseManager::DBMS, true) ? [$value] : null;
    }
}
