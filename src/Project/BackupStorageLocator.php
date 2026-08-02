<?php

declare(strict_types=1);

namespace DockerCli\Project;

use DockerCli\Panel\BackupsSettingsRepository;

use function DockerCli\Util\join_path;

final readonly class BackupStorageLocator
{
    public function __construct(private ?BackupsSettingsRepository $settings = null)
    {
    }

    public function databaseDirectory(string $location, string $database): string
    {
        foreach (($this->settings ?? new BackupsSettingsRepository())->locations() as $storage) {
            if ($storage['code'] === $location) {
                return join_path($storage['path'], $database);
            }
        }

        throw new \InvalidArgumentException(sprintf('Хранилище бэкапов с кодом «%s» не найдено.', $location));
    }
}
