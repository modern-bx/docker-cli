<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use Symfony\Component\Yaml\Yaml;

use function DockerCli\Util\join_path;

final class SecuritySettingsRepository
{
    public const DEFAULT_SESSION_HOURS = 8;
    public const MAX_SESSION_HOURS = 8760;

    private readonly string $file;

    public function __construct(?string $file = null)
    {
        if ($file === null) {
            $home = getenv('HOME') ?: throw new \RuntimeException('HOME environment variable is not set.');
            $file = join_path($home, '.config', 'docker-cli', 'state', 'panel', 'settings', 'security.yaml');
        }
        $this->file = $file;
    }

    public function sessionHours(): int
    {
        if (!is_file($this->file)) return self::DEFAULT_SESSION_HOURS;
        $data = Yaml::parseFile($this->file);
        $settings = is_array($data)
            && ($data['meta']['schema'] ?? null) === 'settings.security'
            && ($data['meta']['version'] ?? null) === 0.1
            && is_array($data['settings.security'] ?? null)
            ? $data['settings.security'] : [];
        $hours = $settings['authorization']['maximum_session_hours'] ?? null;
        return is_int($hours) && $hours >= 1 && $hours <= self::MAX_SESSION_HOURS
            ? $hours
            : self::DEFAULT_SESSION_HOURS;
    }

    public function saveSessionHours(int $hours): void
    {
        if ($hours < 1 || $hours > self::MAX_SESSION_HOURS) {
            throw new \InvalidArgumentException(sprintf('Длительность сессии должна быть от 1 до %d часов.', self::MAX_SESSION_HOURS));
        }
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create settings directory "%s".', $directory));
        }
        $contents = Yaml::dump([
            'meta' => ['schema' => 'settings.security', 'version' => 0.1],
            'settings.security' => ['authorization' => ['maximum_session_hours' => $hours]],
        ], 4, 2);
        if (file_put_contents($this->file, $contents, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to write security settings "%s".', $this->file));
        }
        chmod($this->file, 0600);
    }
}
