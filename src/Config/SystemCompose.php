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

    public function init(bool $updateStatic = false, bool $migrateEditable = false): bool
    {
        $directory = $this->directory();
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create config directory "%s".', $directory));
        }

        $created = false;
        foreach ($this->templateMap() as $target => $template) {
            if (file_exists($target)) {
                continue;
            }

            $this->copyTemplate($template, $target);
            $created = true;
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
    private function dataDirectories(): array
    {
        $data = join_path($this->directory(), 'data');

        return [
            join_path($data, 'mysql', 'data'),
            join_path($data, 'mysql', 'logs'),
            join_path($data, 'postgres', 'data'),
            join_path($data, 'postgres', 'logs'),
            join_path($this->directory(), 'config', 'openresty', 'hosts'),
            join_path($this->directory(), 'config', 'php-fpm-8.2', 'php', 'conf.d'),
            join_path($this->directory(), 'config', 'php-fpm-8.2', 'php-fpm.d'),
        ];
    }

    /** @return array<string, string> */
    private function templateMap(): array
    {
        return $this->editableTemplateMap() + $this->staticTemplateMap();
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
        $resources = join_path(dirname(__DIR__, 2), 'resources', 'compose', 'system');

        return [
            $this->composeFile() => join_path($resources, self::COMPOSE_FILE),
            join_path($this->directory(), 'config', 'php-fpm-8.2') => join_path($resources, 'config', 'php-fpm-8.2'),
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

