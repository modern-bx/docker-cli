<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use Symfony\Component\Yaml\Yaml;
use DockerCli\Project\ProjectNameGenerator;

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

    /** @return list<array{path: string, code: string, default: bool}> */
    public function locations(): array
    {
        return $this->normalizeLocations($this->storedLocations('locations'));
    }

    /** @return list<array{path: string, code: string, default: bool}> */
    public function databaseLocations(): array
    {
        return $this->normalizeLocations($this->storedLocations('databaseLocations'));
    }

    /** @param list<array{path: string, code?: mixed, default: bool}> $valid
     *  @return list<array{path: string, code: string, default: bool}>
     */
    private function normalizeLocations(array $valid): array
    {
        $used = [];
        foreach ($valid as &$location) {
            $code = $location['code'] ?? '';
            if (!is_string($code) || $code === '' || isset($used[$code])) {
                $code = (new ProjectNameGenerator())->generate(array_keys($used));
            }
            $location['code'] = $code;
            $used[$code] = true;
        }
        return $valid;
    }

    /** @param list<array{path: string, code: string, default: bool}> $locations
     *  @param list<array{path: string, code: string, default: bool}> $databaseLocations
     *  @return array{locations: list<array{path: string, code: string, default: bool}>, databaseLocations: list<array{path: string, code: string, default: bool}>}
     */
    public function save(array $locations, array $databaseLocations): array
    {
        $locations = $this->prepareLocations($locations, 'locations');
        $databaseLocations = $this->prepareLocations($databaseLocations, 'databaseLocations');
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create settings directory "%s".', $directory));
        }
        $contents = Yaml::dump([
            'meta' => ['schema' => 'settings.projects', 'version' => 0.1],
            'settings.projects' => ['locations' => $locations, 'databaseLocations' => $databaseLocations],
        ], 4, 2);
        if (file_put_contents($this->file, $contents, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to write projects settings "%s".', $this->file));
        }
        chmod($this->file, 0600);
        return ['locations' => $locations, 'databaseLocations' => $databaseLocations];
    }

    /** @param list<array{path: string, code: string, default: bool}> $locations
     *  @return list<array{path: string, code: string, default: bool}>
     */
    private function prepareLocations(array $locations, string $key): array
    {
        // Use the values actually persisted in the file to distinguish an
        // existing location from a newly added one. Existing location codes may
        // be changed, but must never be saved empty.
        $previous = $this->storedLocations($key);
        $previousByPath = array_column($previous, null, 'path');
        $used = [];
        foreach ($locations as $index => &$location) {
            $isExisting = isset($previousByPath[$location['path']]) || array_key_exists($index, $previous);
            if ($isExisting && $location['code'] === '') {
                throw new \InvalidArgumentException('Код существующего расположения не может быть пустым.');
            }
            if ($location['code'] === '') {
                $location['code'] = (new ProjectNameGenerator())->generate(array_keys($used));
            }
            if (isset($used[$location['code']])) throw new \InvalidArgumentException('Коды расположений должны быть уникальными.');
            $used[$location['code']] = true;
        }
        foreach ($locations as $location) {
            $path = $location['path'];
            if (!is_dir($path)) {
                throw new \InvalidArgumentException(sprintf('Путь «%s» не существует или не является каталогом.', $path));
            }
            if (!is_readable($path) || !is_writable($path) || !is_executable($path) || @scandir($path) === false) {
                throw new \InvalidArgumentException(sprintf('Каталог «%s» должен быть доступен для чтения, записи и листинга.', $path));
            }
        }
        return $locations;
    }

    /** @return list<array{path: string, code?: mixed, default: bool}> */
    private function storedLocations(string $key): array
    {
        if (!is_file($this->file)) return [];
        $data = Yaml::parseFile($this->file);
        $locations = is_array($data)
            && ($data['meta']['schema'] ?? null) === 'settings.projects'
            && ($data['meta']['version'] ?? null) === 0.1
            && is_array($data['settings.projects'][$key] ?? null)
            ? $data['settings.projects'][$key] : [];

        return array_values(array_filter($locations, static fn ($item): bool => is_array($item)
            && is_string($item['path'] ?? null) && is_bool($item['default'] ?? null)));
    }
}
