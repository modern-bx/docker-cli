<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use Symfony\Component\Yaml\Yaml;
use DockerCli\Project\ProjectNameGenerator;

use function DockerCli\Util\join_path;

final class BackupsSettingsRepository
{
    private readonly string $file;

    public function __construct(?string $file = null)
    {
        if ($file === null) {
            $home = getenv('HOME') ?: throw new \RuntimeException('HOME environment variable is not set.');
            $file = join_path($home, '.config', 'docker-cli', 'state', 'panel', 'settings', 'backups.yaml');
        }
        $this->file = $file;
    }

    /** @return list<array{path: string, code: string, default: bool}> */
    public function locations(): array
    {
        $valid = $this->storedLocations();
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

    /** @return list<array{name: string, code: string, include: list<string>, exclude: list<string>}> */
    public function fileStrategies(): array
    {
        return $this->storedSettings()['fileStrategies'];
    }

    /**
     * @param list<array{path: string, code: string, default: bool}> $locations
     * @param list<array{name: string, code: string, include: list<string>, exclude: list<string>}> $fileStrategies
     * @return array{locations: list<array{path: string, code: string, default: bool}>, fileStrategies: list<array{name: string, code: string, include: list<string>, exclude: list<string>}>}
     */
    public function save(array $locations, array $fileStrategies): array
    {
        // Use the values actually persisted in the file to distinguish an
        // existing location from a newly added one. Existing location codes may
        // be changed, but must never be saved empty.
        $previous = $this->storedLocations();
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
        $usedStrategies = [];
        foreach ($fileStrategies as &$strategy) {
            if ($strategy['code'] === '') {
                $strategy['code'] = (new ProjectNameGenerator())->generate(array_keys($usedStrategies));
            }
            if (isset($usedStrategies[$strategy['code']])) throw new \InvalidArgumentException('Коды файловых стратегий должны быть уникальными.');
            $usedStrategies[$strategy['code']] = true;
        }
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create settings directory "%s".', $directory));
        }
        $contents = Yaml::dump([
            'meta' => ['schema' => 'settings.backups', 'version' => 0.1],
            'settings.backups' => ['locations' => $locations, 'fileStrategies' => $fileStrategies],
        ], 4, 2);
        if (file_put_contents($this->file, $contents, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to write backups settings "%s".', $this->file));
        }
        chmod($this->file, 0600);
        return ['locations' => $locations, 'fileStrategies' => $fileStrategies];
    }

    /** @return list<array{path: string, code?: mixed, default: bool}> */
    private function storedLocations(): array
    {
        return $this->storedSettings()['locations'];
    }

    /** @return array{locations: list<array{path: string, code?: mixed, default: bool}>, fileStrategies: list<array{name: string, code: string, include: list<string>, exclude: list<string>}>} */
    private function storedSettings(): array
    {
        $data = is_file($this->file) ? Yaml::parseFile($this->file) : [];
        $settings = is_array($data) && ($data['meta']['schema'] ?? null) === 'settings.backups'
            && ($data['meta']['version'] ?? null) === 0.1 && is_array($data['settings.backups'] ?? null)
            ? $data['settings.backups'] : [];
        $locations = array_values(array_filter(is_array($settings['locations'] ?? null) ? $settings['locations'] : [], static fn ($item): bool => is_array($item)
            && is_string($item['path'] ?? null) && is_bool($item['default'] ?? null)));
        $strategies = array_values(array_filter(is_array($settings['fileStrategies'] ?? null) ? $settings['fileStrategies'] : [], static fn ($item): bool => is_array($item)
            && is_string($item['name'] ?? null) && is_string($item['code'] ?? null)
            && is_array($item['include'] ?? null) && array_is_list($item['include']) && !array_filter($item['include'], static fn ($value): bool => !is_string($value))
            && is_array($item['exclude'] ?? null) && array_is_list($item['exclude']) && !array_filter($item['exclude'], static fn ($value): bool => !is_string($value))));
        return ['locations' => $locations, 'fileStrategies' => $strategies];
    }
}
