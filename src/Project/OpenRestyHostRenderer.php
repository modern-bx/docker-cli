<?php

declare(strict_types=1);

namespace DockerCli\Project;

use DockerCli\Config\SystemCompose;
use Symfony\Component\Yaml\Yaml;
use function DockerCli\Util\join_path;

final class OpenRestyHostRenderer
{
    private const HOSTS_RELATIVE_PATH = 'config/openresty/hosts';

    public function render(): void
    {
        $compose = new SystemCompose();
        $hostsDirectory = $this->hostsDirectory($compose);
        if (!is_dir($hostsDirectory) && !mkdir($hostsDirectory, 0755, true) && !is_dir($hostsDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create OpenResty hosts directory "%s".', $hostsDirectory));
        }

        foreach (glob(join_path($hostsDirectory, '*.web.conf')) ?: [] as $hostFile) {
            unlink($hostFile);
        }

        $baseHost = $this->readBaseHost($compose->envFile());
        foreach ($this->registeredProjects() as $project) {
            $template = $this->templateFile($project['framework']);
            if (!is_file($template)) {
                throw new \RuntimeException(sprintf('OpenResty host template for framework "%s" not found.', $project['framework']));
            }

            $contents = file_get_contents($template);
            if ($contents === false) {
                throw new \RuntimeException(sprintf('Unable to read OpenResty host template "%s".', $template));
            }

            $hostName = sprintf('web-%s.%s', $project['name'], $baseHost);
            $target = join_path($hostsDirectory, $project['name'] . '.web.conf');
            file_put_contents($target, strtr($contents, [
                '{{ project_name }}' => $project['name'],
                '{{ host_name }}' => $hostName,
                '{{ document_root }}' => join_path('/host', $project['document_root']),
            ]));
        }
    }

    public function hostsDirectory(SystemCompose $compose): string
    {
        return join_path($compose->directory(), self::HOSTS_RELATIVE_PATH);
    }

    /** @return list<array{name: string, framework: string, document_root: string}> */
    private function registeredProjects(): array
    {
        $projectsDirectory = $this->projectsDirectory();
        if (!is_dir($projectsDirectory)) {
            return [];
        }

        $projects = [];
        foreach (glob(join_path($projectsDirectory, '*', 'project.yaml')) ?: [] as $projectFile) {
            $data = Yaml::parseFile($projectFile);
            if (!is_array($data)) {
                continue;
            }

            $project = $data['data']['project'] ?? null;
            if (!is_array($project)) {
                continue;
            }

            $name = $project['name'] ?? null;
            $framework = $project['framework'] ?? null;
            $documentRoot = $project['document_root'] ?? null;
            if (is_string($name) && is_string($framework) && is_string($documentRoot)) {
                $projects[] = [
                    'name' => $name,
                    'framework' => $framework,
                    'document_root' => $documentRoot,
                ];
            }
        }

        return $projects;
    }

    private function projectsDirectory(): string
    {
        $home = getenv('HOME') ?: throw new \RuntimeException('HOME environment variable is not set.');

        return join_path($home, '.config', 'docker-cli', 'projects');
    }

    private function templateFile(string $framework): string
    {
        return join_path(dirname(__DIR__, 2), 'resources', 'compose', 'system', self::HOSTS_RELATIVE_PATH, $framework, 'web.conf');
    }

    private function readBaseHost(string $envFile): string
    {
        if (!is_file($envFile)) {
            return 'local.kubehut.top';
        }

        foreach (file($envFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            if (trim($key) === 'BASE_HOST') {
                return trim($value, " \t\n\r\0\x0B\"'");
            }
        }

        return 'local.kubehut.top';
    }
}
