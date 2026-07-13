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

    public function init(): bool
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

            copy($template, $target);
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
        ];
    }

    /** @return array<string, string> */
    private function templateMap(): array
    {
        $resources = join_path(dirname(__DIR__, 2), 'resources', 'compose', 'system');

        return [
            $this->envFile() => join_path($resources, self::ENV_FILE),
            $this->composeFile() => join_path($resources, self::COMPOSE_FILE),
        ];
    }
}
