<?php

declare(strict_types=1);

namespace DockerCli\Config;

use function DockerCli\Util\join_path;

final class SystemCompose
{
    public const PROJECT_NAME = 'docker-cli';
    public const CONFIG_RELATIVE_PATH = '.config/docker-cli/compose/system';
    public const COMPOSE_FILE = 'compose.yaml';
    public const ENV_FILE = '.env';

    public function directory(): string
    {
        $home = getenv('HOME') ?: throw new \RuntimeException('HOME environment variable is not set.');

        return join_path($home, self::CONFIG_RELATIVE_PATH);
    }

    public function composeFile(): string
    {
        return join_path($this->directory(), self::COMPOSE_FILE);
    }

    public function envFile(): string
    {
        return join_path($this->directory(), self::ENV_FILE);
    }

    public function playwrightScriptsDirectory(): string
    {
        $home = getenv('HOME') ?: throw new \RuntimeException('HOME environment variable is not set.');

        return join_path($home, '.config', 'docker-cli', 'actions', 'playwright', 'scripts');
    }

    public function playwrightDataDirectory(): string
    {
        return join_path($this->directory(), 'playwright', 'data');
    }

    public function coreTasksDirectory(): string
    {
        $home = getenv('HOME') ?: throw new \RuntimeException('HOME environment variable is not set.');

        return join_path($home, '.config', 'docker-cli', 'actions', 'tasks', 'core');
    }

    public function init(bool $updateStatic = false, bool $migrateEditable = false): bool
    {
        $directory = $this->directory();
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create config directory "%s".', $directory));
        }

        $created = false;
        foreach ($this->templateMap() as $target => $template) {
            if (file_exists($target) && !is_dir($template)) {
                continue;
            }

            $before = $this->snapshotFiles($target);
            $this->copyTemplate($template, $target);
            if ($before !== $this->snapshotFiles($target)) {
                $created = true;
            }
        }

        if ($updateStatic) {
            foreach ($this->staticTemplateMap() as $target => $template) {
                $this->copyTemplate($template, $target, true);
                $created = true;
            }
        }

        if ($migrateEditable) {
            foreach ($this->editableTemplateMap() as $target => $template) {
                if ($this->migrateEnvFile($target, $template)) {
                    $created = true;
                }
            }
        }

        if ($this->ensureHostIdentityEnv()) {
            $created = true;
        }
        if ($this->ensurePanelSecrets()) {
            $created = true;
        }

        foreach ($this->dataDirectories() as $dataDirectory) {
            if (is_dir($dataDirectory)) {
                continue;
            }

            if (!mkdir($dataDirectory, 0755, true) && !is_dir($dataDirectory)) {
                throw new \RuntimeException(sprintf('Unable to create data directory "%s".', $dataDirectory));
            }

            $created = true;
        }

        return $created;
    }

    public function assertInitialized(): void
    {
        $missingFiles = $this->missingFiles();
        if ($missingFiles !== []) {
            throw new MissingConfigException($missingFiles, $this->directory());
        }
    }

    /** @return list<string> */
    public function missingFiles(): array
    {
        return array_values(array_filter(
            [$this->envFile(), $this->composeFile()],
            static fn (string $file): bool => !is_file($file)
        ));
    }

    /** @return array<string, string> */
    public function dockerProcessEnvironment(): array
    {
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }

        if (!is_file($this->envFile())) {
            return $environment;
        }

        $contents = file_get_contents($this->envFile());
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read env file "%s".', $this->envFile()));
        }

        $values = $this->readEnvValues($contents);
        $buildKit = $values['SOURCE_IMAGE_DOCKER_BUILDKIT'] ?? '';
        if ($buildKit !== '') {
            $environment['DOCKER_BUILDKIT'] = $buildKit;
            $environment['COMPOSE_DOCKER_CLI_BUILD'] = $buildKit;
        }

        return $environment;
    }

    public function envValue(string $key, string $default = ''): string
    {
        if (!is_file($this->envFile())) {
            return $default;
        }

        $contents = file_get_contents($this->envFile());
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read env file "%s".', $this->envFile()));
        }

        return $this->readEnvValues($contents)[$key] ?? $default;
    }

    /** @return list<string> */
    public function dockerComposeCommand(string $operation): array
    {
        return [
            'docker',
            'compose',
            '--project-name',
            self::PROJECT_NAME,
            '--env-file',
            $this->envFile(),
            '--file',
            $this->composeFile(),
            $operation,
        ];
    }

    /** @return list<string> */
    private function snapshotFiles(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        if (is_file($path)) {
            return [$path];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function ensureHostIdentityEnv(): bool
    {
        $envFile = $this->envFile();
        if (!is_file($envFile)) {
            return false;
        }

        $contents = file_get_contents($envFile);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read env file "%s".', $envFile));
        }

        $replacements = [
            'HOST_UID' => (string) $this->hostUserId(),
            'HOST_GID' => (string) $this->hostGroupId(),
        ];
        $currentValues = $this->readEnvValues($contents);
        $updated = $contents;
        foreach ($replacements as $key => $value) {
            if (($currentValues[$key] ?? '') !== '') {
                continue;
            }

            if (preg_match('/^' . $key . '=.*$/m', $updated) === 1) {
                $updated = preg_replace('/^' . $key . '=.*$/m', $key . '=' . $value, $updated) ?? $updated;
                continue;
            }

            $separator = str_ends_with($updated, PHP_EOL) || $updated === '' ? '' : PHP_EOL;
            $updated .= $separator . $key . '=' . $value . PHP_EOL;
        }

        if ($updated === $contents) {
            return false;
        }

        file_put_contents($envFile, $updated);

        return true;
    }

    private function ensurePanelSecrets(): bool
    {
        $envFile = $this->envFile();
        if (!is_file($envFile)) {
            return false;
        }

        $contents = file_get_contents($envFile);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read env file "%s".', $envFile));
        }
        $updated = $contents;
        $values = $this->readEnvValues($contents);
        foreach (['PANEL_PASSWORD_SALT', 'PANEL_JWT_SECRET'] as $key) {
            if (($values[$key] ?? '') !== '') {
                continue;
            }
            $line = $key . '=' . bin2hex(random_bytes(32));
            if (preg_match('/^' . $key . '=.*$/m', $updated) === 1) {
                $updated = preg_replace('/^' . $key . '=.*$/m', $line, $updated) ?? $updated;
            } else {
                $separator = str_ends_with($updated, PHP_EOL) || $updated === '' ? '' : PHP_EOL;
                $updated .= $separator . $line . PHP_EOL;
            }
        }
        if ($updated === $contents) {
            return false;
        }
        if (!is_string($updated) || file_put_contents($envFile, $updated, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to write env file "%s".', $envFile));
        }

        return true;
    }

    private function hostUserId(): int
    {
        if (function_exists('posix_getuid')) {
            return posix_getuid();
        }

        $uid = getenv('UID');

        return is_string($uid) && ctype_digit($uid) ? (int) $uid : 1000;
    }

    private function hostGroupId(): int
    {
        if (function_exists('posix_getgid')) {
            return posix_getgid();
        }

        $gid = getenv('GID');

        return is_string($gid) && ctype_digit($gid) ? (int) $gid : 1000;
    }

    /** @return list<string> */
    private function dataDirectories(): array
    {
        $data = join_path($this->directory(), 'data');

        return [
            join_path($data, 'mysql', 'data'),
            join_path($data, 'mysql', 'logs'),
            join_path($data, 'postgres', 'data'),
            join_path($data, 'postgres', 'logs'),
            join_path($this->directory(), 'config', 'openresty', 'hosts'),
            join_path($this->directory(), 'config', 'panel'),
            join_path($this->directory(), 'config', 'php-fpm-8.2', 'php', 'conf.d'),
            join_path($this->directory(), 'config', 'php-fpm-8.2', 'php-fpm.d'),
            $this->playwrightScriptsDirectory(),
        ];
    }

    /** @return array<string, string> */
    private function templateMap(): array
    {
        return $this->editableTemplateMap() + $this->staticTemplateMap() + $this->playwrightDataTemplateMap();
    }

    private function copyTemplate(string $source, string $target, bool $overwrite = false): void
    {
        if (is_dir($source)) {
            if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                throw new \RuntimeException(sprintf('Unable to create config directory "%s".', $target));
            }

            foreach (scandir($source) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $this->copyTemplate(join_path($source, $entry), join_path($target, $entry), $overwrite);
            }

            return;
        }

        if (file_exists($target) && !$overwrite) {
            return;
        }

        $targetDirectory = dirname($target);
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0755, true) && !is_dir($targetDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create config directory "%s".', $targetDirectory));
        }

        copy($source, $target);
    }

    /** @return array<string, string> */
    private function staticTemplateMap(): array
    {
        $resources = join_path(dirname(__DIR__, 2), 'resources');
        $composeResources = join_path($resources, 'compose', 'system');

        return [
            $this->composeFile() => join_path($composeResources, self::COMPOSE_FILE),
            join_path($this->directory(), 'config', 'playwright') => join_path($composeResources, 'config', 'playwright'),
            join_path($this->directory(), 'config', 'php-fpm-8.2') => join_path($composeResources, 'config', 'php-fpm-8.2'),
            join_path($this->directory(), 'config', 'panel') => join_path($composeResources, 'config', 'panel'),
            $this->playwrightScriptsDirectory() => join_path($resources, 'playwright', 'scripts'),
            $this->coreTasksDirectory() => join_path($resources, 'tasks', 'core'),
        ];
    }

    /** @return array<string, string> */
    private function playwrightDataTemplateMap(): array
    {
        $resources = join_path(dirname(__DIR__, 2), 'resources');

        return [
            $this->playwrightDataDirectory() => join_path($resources, 'playwright', 'data'),
        ];
    }

    /** @return array<string, string> */
    private function editableTemplateMap(): array
    {
        $resources = join_path(dirname(__DIR__, 2), 'resources', 'compose', 'system');

        return [
            $this->envFile() => join_path($resources, self::ENV_FILE),
        ];
    }

    private function migrateEnvFile(string $target, string $template): bool
    {
        if (!is_file($target)) {
            copy($template, $target);

            return true;
        }

        $currentContents = file_get_contents($target);
        if ($currentContents === false) {
            throw new \RuntimeException(sprintf('Unable to read env file "%s".', $target));
        }

        $templateContents = file_get_contents($template);
        if ($templateContents === false) {
            throw new \RuntimeException(sprintf('Unable to read env template "%s".', $template));
        }

        $currentValues = $this->readEnvValues($currentContents);
        $templateValues = $this->readEnvValues($templateContents);
        $missingLines = [];
        foreach ($templateValues as $key => $value) {
            if (!array_key_exists($key, $currentValues)) {
                $missingLines[] = $key . '=' . $value;
            }
        }

        if ($missingLines === []) {
            return false;
        }

        $separator = str_ends_with($currentContents, PHP_EOL) || $currentContents === '' ? '' : PHP_EOL;
        file_put_contents($target, $currentContents . $separator . implode(PHP_EOL, $missingLines) . PHP_EOL);

        return true;
    }

    /** @return array<string, string> */
    private function readEnvValues(string $contents): array
    {
        $values = [];
        foreach (explode(PHP_EOL, $contents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
        }

        return $values;
    }
}
