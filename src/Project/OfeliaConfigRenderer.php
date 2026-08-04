<?php

declare(strict_types=1);

namespace DockerCli\Project;

use DockerCli\Config\SystemCompose;
use function DockerCli\Util\join_path;

final class OfeliaConfigRenderer
{
    public function __construct(private readonly ?ProjectRegistry $projects = null, private readonly ?SystemCompose $compose = null) {}

    public function render(): string
    {
        $projects = $this->projects ?? new ProjectRegistry();
        $compose = $this->compose ?? new SystemCompose();
        $file = join_path($compose->directory(), 'config', 'ofelia', 'config.ini');
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0755, true) && !is_dir(dirname($file))) {
            throw new \RuntimeException(sprintf('Не удалось создать директорию конфигурации Ofelia "%s".', dirname($file)));
        }

        $lines = ['[global]', ''];
        foreach ($projects->registeredProjectNames() as $projectName) {
            $config = $projects->readProjectConfig($projectName);
            $project = is_array($config['data']['project'] ?? null) ? $config['data']['project'] : [];
            $root = is_string($project['root'] ?? null) ? $project['root'] : '';
            $version = is_string($project['language_version'] ?? null) ? $project['language_version'] : PhpLanguageVersion::default($compose);
            foreach (is_array($project['schedule'] ?? null) ? $project['schedule'] : [] as $index => $item) {
                if (!is_array($item) || ($item['enabled'] ?? true) === false) continue;
                $schedule = is_string($item['schedule'] ?? null) ? trim($item['schedule']) : '';
                $command = is_string($item['command'] ?? null) ? trim($item['command']) : '';
                if ($schedule === '' || $command === '') continue;
                $workingDirectory = is_string($item['workingDirectory'] ?? null) ? trim($item['workingDirectory']) : '';
                $directory = $workingDirectory === '' ? $root : (str_starts_with($workingDirectory, '/') ? $workingDirectory : join_path($root, $workingDirectory));
                $shellCommand = sprintf('cd %s && exec %s', escapeshellarg($directory), $command);
                $lines[] = sprintf('[job-exec "%s-%d"]', preg_replace('/[^A-Za-z0-9_-]+/', '-', $projectName), $index);
                $lines[] = 'schedule = 0 ' . $schedule;
                $lines[] = 'container = docker-cli-php-fpm-' . $version;
                $lines[] = 'command = /bin/sh -lc ' . escapeshellarg($shellCommand);
                $lines[] = '';
            }
        }
        if (file_put_contents($file, implode("\n", $lines), LOCK_EX) === false) throw new \RuntimeException('Не удалось записать конфигурацию Ofelia.');

        return $file;
    }
}
