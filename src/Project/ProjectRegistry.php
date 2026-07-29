<?php

declare(strict_types=1);

namespace DockerCli\Project;

use Symfony\Component\Yaml\Yaml;
use function DockerCli\Util\join_path;

final class ProjectRegistry
{
    public function projectsDirectory(): string
    {
        $home = getenv('HOME') ?: null;
        if ($home === null) {
            throw new \RuntimeException('Unable to determine HOME directory.');
        }

        return join_path($home, '.config', 'docker-cli', 'projects');
    }

    /** @return list<string> */
    public function registeredProjectNames(): array
    {
        $projectsDirectory = $this->projectsDirectory();
        if (!is_dir($projectsDirectory)) {
            return [];
        }

        $names = [];
        foreach (glob(join_path($projectsDirectory, '*'), GLOB_ONLYDIR) ?: [] as $directory) {
            $names[] = basename($directory);
        }

        sort($names, SORT_STRING);

        return $names;
    }

    public function projectDirectory(string $projectName): string
    {
        return join_path($this->projectsDirectory(), $projectName);
    }

    public function projectConfigFile(string $projectName): string
    {
        return join_path($this->projectDirectory($projectName), 'project.yaml');
    }

    public function hasProject(string $projectName): bool
    {
        return is_file($this->projectConfigFile($projectName));
    }

    public function isProjectProtected(string $projectName): bool
    {
        return ($this->readProjectConfig($projectName)['data']['project']['protected'] ?? false) === true;
    }

    /** @return array<string, mixed> */
    public function readProjectConfig(string $projectName): array
    {
        $file = $this->projectConfigFile($projectName);
        $data = Yaml::parseFile($file);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $config */
    public function writeProjectConfig(string $projectName, array $config): void
    {
        file_put_contents($this->projectConfigFile($projectName), Yaml::dump($config, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
    }

    public function projectNameFromContext(?string $startDirectory = null): ?string
    {
        $directory = $startDirectory ?? getcwd();
        if (!is_string($directory) || $directory === '') {
            return null;
        }

        $directory = realpath($directory) ?: $directory;
        while (true) {
            $metaFile = join_path($directory, '.docker-cli', 'project.yaml');
            if (is_file($metaFile)) {
                $data = Yaml::parseFile($metaFile);
                $name = is_array($data) ? ($data['data']['project']['name'] ?? null) : null;

                return is_string($name) && $name !== '' ? $name : null;
            }

            $parent = dirname($directory);
            if ($parent === $directory) {
                return null;
            }
            $directory = $parent;
        }
    }
}
