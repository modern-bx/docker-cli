<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use Symfony\Component\Yaml\Yaml;

use function DockerCli\Util\join_path;

final class ProjectsSettingsRepository
{
    private readonly string $file;

    public function __construct(?string $file = null)
    {
        if ($file === null) {
            $home = getenv('HOME') ?: throw new \RuntimeException('HOME environment variable is not set.');
            $file = join_path($home, '.config', 'docker-cli', 'state', 'panel', 'settings', 'projects.yaml');
        }
        $this->file = $file;
    }

    /** @return list<array{path: string, default: bool}> */
    public function locations(): array
    {
        if (!is_file($this->file)) return [];
        $data = Yaml::parseFile($this->file);
        $locations = is_array($data)
            && ($data['meta']['schema'] ?? null) === 'settings.projects'
            && ($data['meta']['version'] ?? null) === 0.1
            && is_array($data['settings.projects']['locations'] ?? null)
            ? $data['settings.projects']['locations'] : [];
        return array_values(array_filter($locations, static fn ($item): bool => is_array($item)
            && is_string($item['path'] ?? null) && is_bool($item['default'] ?? null)));
    }

    /** @param list<array{path: string, default: bool}> $locations */
    public function save(array $locations): void
    {
        foreach ($locations as $location) {
            $path = $location['path'];
            if (!is_dir($path)) {
                throw new \InvalidArgumentException(sprintf('Путь «%s» не существует или не является каталогом.', $path));
            }
            if (!is_readable($path) || !is_writable($path) || !is_executable($path) || @scandir($path) === false) {
                throw new \InvalidArgumentException(sprintf('Каталог «%s» должен быть доступен для чтения, записи и листинга.', $path));
            }
        }
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create settings directory "%s".', $directory));
        }
        $contents = Yaml::dump([
            'meta' => ['schema' => 'settings.projects', 'version' => 0.1],
            'settings.projects' => ['locations' => $locations],
        ], 4, 2);
        if (file_put_contents($this->file, $contents, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to write projects settings "%s".', $this->file));
        }
        chmod($this->file, 0600);
    }
}
