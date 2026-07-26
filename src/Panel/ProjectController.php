<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use DockerCli\Config\SystemCompose;
use DockerCli\Panel\Dto\ProjectDto;
use DockerCli\Panel\Dto\ProjectListDto;
use DockerCli\Project\OpenRestyHostRenderer;
use DockerCli\Project\ProjectRegistry;

final class ProjectController
{
    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly ?SystemCompose $compose = null,
    )
    {
    }

    public function act(string $name, string $action): ProjectListDto
    {
        if (!$this->projects->hasProject($name)) {
            throw new ProjectActionException('Проект не найден.', 404);
        }
        $config = $this->projects->readProjectConfig($name);
        $project = is_array($config['data']['project'] ?? null) ? $config['data']['project'] : null;
        if ($project === null) {
            throw new ProjectActionException('Конфигурация проекта повреждена.', 422);
        }

        if ($action === 'enable' || $action === 'disable') {
            $config['data']['project']['enabled'] = $action === 'enable';
            $this->projects->writeProjectConfig($name, $config);
            (new OpenRestyHostRenderer())->render();
            $this->reloadOpenResty();
        } elseif ($action === 'wipe') {
            $this->wipe($name, $project);
        } else {
            throw new ProjectActionException('Неизвестное действие.', 400);
        }

        return $this->index();
    }

    public function index(): ProjectListDto
    {
        $projects = [];
        foreach ($this->projects->registeredProjectNames() as $name) {
            $config = $this->projects->readProjectConfig($name);
            $project = is_array($config['data']['project'] ?? null) ? $config['data']['project'] : [];
            $projects[] = new ProjectDto(
                name: is_string($project['name'] ?? null) && $project['name'] !== '' ? $project['name'] : $name,
                language: $this->nullableString($project['language'] ?? null),
                framework: $this->nullableString($project['framework'] ?? null),
                // Older project configs predate this flag and are enabled by default,
                // just like OpenRestyHostRenderer treats them.
                enabled: ($project['enabled'] ?? true) !== false,
            );
        }

        return new ProjectListDto($projects);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $project */
    private function wipe(string $name, array $project): void
    {
        $root = is_string($project['root'] ?? null) ? realpath($project['root']) : false;
        if ($root === false || $root === DIRECTORY_SEPARATOR || !is_dir($root . '/.docker-cli')) {
            throw new ProjectActionException(sprintf('Небезопасная или отсутствующая директория проекта "%s".', $name), 422);
        }
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && $entry !== '.docker-cli') {
                $this->removePath($root . DIRECTORY_SEPARATOR . $entry);
            }
        }
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            if (!unlink($path)) throw new ProjectActionException(sprintf('Не удалось удалить "%s".', $path));
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') $this->removePath($path . DIRECTORY_SEPARATOR . $entry);
        }
        if (!rmdir($path)) throw new ProjectActionException(sprintf('Не удалось удалить директорию "%s".', $path));
    }

    private function reloadOpenResty(): void
    {
        $compose = $this->compose ?? new SystemCompose();
        $command = [...$compose->dockerComposeCommand('exec'), '--no-TTY', 'openresty', 'openresty', '-s', 'reload'];
        $process = proc_open($command, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $compose->directory(), $compose->dockerProcessEnvironment());
        if (!is_resource($process)) throw new ProjectActionException('Не удалось перезагрузить конфигурацию OpenResty.');
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) throw new ProjectActionException(trim($stdout . "\n" . $stderr) ?: 'Не удалось перезагрузить конфигурацию OpenResty.');
    }
}
