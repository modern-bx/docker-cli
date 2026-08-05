<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use function DockerCli\Util\join_path;

final readonly class HookRepository
{
    private const OUTPUT_LIMIT = 65536;

    public function __construct(private ?string $hooksDirectory = null)
    {
    }

    /** @return list<array{id: string, level: string, command: string, timing: string, enabled: bool, hook: string}> */
    public function all(): array
    {
        $commandsDirectory = join_path($this->directory(), 'commands');
        if (!is_dir($commandsDirectory)) {
            return [];
        }

        $hooks = [];
        foreach (scandir($commandsDirectory) ?: [] as $directoryName) {
            if ($directoryName === '.' || $directoryName === '..') {
                continue;
            }

            $directory = join_path($commandsDirectory, $directoryName);
            if (!is_dir($directory) || !preg_match('/^(?<command>.+)\.(?<timing>before|after)$/', $directoryName, $matches)) {
                continue;
            }

            foreach (scandir($directory) ?: [] as $fileName) {
                $file = join_path($directory, $fileName);
                if ($fileName === '.' || $fileName === '..' || !is_file($file)) {
                    continue;
                }

                $hooks[] = [
                    'id' => 'commands/' . $directoryName . '/' . $fileName,
                    'level' => 'command',
                    'command' => str_replace('.', ':', $matches['command']),
                    'timing' => $matches['timing'],
                    'enabled' => !str_starts_with($fileName, '.'),
                    'hook' => $fileName,
                ];
            }
        }

        usort($hooks, static fn (array $left, array $right): int => [$left['level'], $left['command'], $left['timing'], $left['hook']] <=> [$right['level'], $right['command'], $right['timing'], $right['hook']]);

        return $hooks;
    }

    public function toggle(string $id): void
    {
        $path = $this->existingHookPath($id);
        $directory = dirname($path);
        $fileName = basename($path);
        $targetName = str_starts_with($fileName, '.') ? substr($fileName, 1) : '.' . $fileName;
        if ($targetName === '') {
            throw new \RuntimeException('Некорректное имя хука.');
        }

        $target = join_path($directory, $targetName);
        if (file_exists($target)) {
            throw new \RuntimeException(sprintf('Файл "%s" уже существует.', $targetName));
        }
        if (!rename($path, $target)) {
            throw new \RuntimeException('Не удалось переключить хук.');
        }
    }

    public function content(string $id): string
    {
        $content = file_get_contents($this->existingHookPath($id));
        if ($content === false) {
            throw new \RuntimeException('Не удалось прочитать хук.');
        }

        return $content;
    }

    public function save(string $id, string $content): void
    {
        $path = $this->existingHookPath($id);
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось сохранить хук.');
        }
    }

    /** @return array{exitCode: int, stdout: string, stderr: string} */
    public function run(string $id, string $profile, string $workingDirectory): array
    {
        $path = $this->existingHookPath($id);
        $arguments = $this->profileArguments($profile);
        $process = proc_open(
            [$path, ...$arguments],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $workingDirectory,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Не удалось запустить хук.');
        }

        $stdout = $this->readOutput($pipes[1]);
        $stderr = $this->readOutput($pipes[2]);
        $exitCode = proc_close($process);

        return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    public function delete(string $id): void
    {
        $path = $this->existingHookPath($id);
        if (!unlink($path)) {
            throw new \RuntimeException('Не удалось удалить хук.');
        }
    }

    /** @return list<string> */
    private function profileArguments(string $profile): array
    {
        $arguments = [];
        foreach (preg_split('/\s+/', trim($profile)) ?: [] as $argument) {
            if ($argument !== '') {
                $arguments[] = $argument;
            }
        }

        return $arguments;
    }

    private function readOutput(mixed $pipe): string
    {
        if (!is_resource($pipe)) {
            return '';
        }

        $output = stream_get_contents($pipe, self::OUTPUT_LIMIT + 1);
        fclose($pipe);
        if ($output === false) {
            return '';
        }
        if (strlen($output) > self::OUTPUT_LIMIT) {
            return substr($output, 0, self::OUTPUT_LIMIT) . "\n… output truncated …\n";
        }

        return $output;
    }

    private function existingHookPath(string $id): string
    {
        $path = $this->hookPath($id);
        if (!is_file($path)) {
            throw new \RuntimeException('Хук не найден.');
        }

        return $path;
    }

    private function hookPath(string $id): string
    {
        $path = join_path($this->directory(), ...explode('/', $id));
        $realRoot = realpath($this->directory());
        $realDirectory = realpath(dirname($path));
        if ($realRoot === false || $realDirectory === false || !str_starts_with($realDirectory . DIRECTORY_SEPARATOR, $realRoot . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Хук не найден.');
        }

        return $path;
    }

    private function directory(): string
    {
        if ($this->hooksDirectory !== null) {
            return $this->hooksDirectory;
        }

        $home = getenv('HOME') ?: throw new \RuntimeException('HOME environment variable is not set.');

        return join_path($home, '.config', 'docker-cli', 'actions', 'hooks');
    }
}
