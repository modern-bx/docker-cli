<?php

declare(strict_types=1);

namespace DockerCli\Hook;

use Symfony\Component\Console\Command\Command;
use function DockerCli\Util\join_path;

final class CommandHookRunner
{
    public function __construct(
        private readonly ?string $hooksDirectory = null,
        private readonly ?HookJournal $journal = null,
    ) {
    }

    /** @param list<string> $arguments */
    public function run(string $command, string $timing, array $arguments): int
    {
        if (!in_array($timing, ['before', 'after'], true)) {
            throw new \InvalidArgumentException(sprintf('Неизвестное время вызова хука: "%s".', $timing));
        }

        $directory = join_path($this->directory(), str_replace(':', '.', $command) . '.' . $timing);
        if (!is_dir($directory)) {
            return Command::SUCCESS;
        }

        $hooks = [];
        foreach (scandir($directory) ?: [] as $name) {
            $file = join_path($directory, $name);
            if ($name !== '.' && $name !== '..' && !str_starts_with($name, '.') && is_file($file) && is_executable($file)) {
                $hooks[] = $file;
            }
        }
        sort($hooks, SORT_STRING);

        foreach ($hooks as $hook) {
            [$exitCode, $stdout, $stderr] = $this->runHook($hook, $command, $timing, $arguments);
            if ($stdout !== '') {
                fwrite(STDOUT, $stdout);
            }
            if ($stderr !== '') {
                fwrite(STDERR, $stderr);
            }

            ($this->journal ?? new HookJournal())->record($this->metadata($hook, $command, $timing, $arguments), $exitCode, $stdout, $stderr);
            if ($exitCode !== Command::SUCCESS) {
                return $exitCode;
            }
        }

        return Command::SUCCESS;
    }

    /** @param list<string> $arguments @return array{int, string, string} */
    private function runHook(string $hook, string $command, string $timing, array $arguments): array
    {
        $process = proc_open(
            [$hook, 'hook:command', $command . ':' . $timing, ...$arguments],
            [STDIN, ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            getcwd() ?: null,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException(sprintf('Не удалось запустить хук "%s".', $hook));
        }

        foreach ([1, 2] as $index) {
            stream_set_blocking($pipes[$index], false);
        }

        $stdout = '';
        $stderr = '';
        while (true) {
            $read = array_values(array_filter([$pipes[1], $pipes[2]], static fn ($pipe): bool => is_resource($pipe) && !feof($pipe)));
            if ($read === []) {
                break;
            }

            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 0, 200000) === false) {
                break;
            }
            foreach ($read as $pipe) {
                $chunk = stream_get_contents($pipe);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($pipe === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        }

        foreach ([1, 2] as $index) {
            fclose($pipes[$index]);
        }

        return [proc_close($process), $stdout, $stderr];
    }

    /** @param list<string> $arguments @return array<string, mixed> */
    private function metadata(string $hook, string $command, string $timing, array $arguments): array
    {
        $project = $this->projectName($arguments);

        return [
            'hook' => basename($hook),
            'command' => $command,
            'timing' => $timing,
            'hookLevel' => 'command',
            'project' => $project,
            'projects' => $project === null ? [] : [$project],
        ];
    }

    /** @param list<string> $arguments */
    private function projectName(array $arguments): ?string
    {
        $skipNext = false;
        foreach ($arguments as $argument) {
            if ($skipNext) {
                $skipNext = false;
                continue;
            }
            if (in_array($argument, ['--language', '--framework'], true)) {
                $skipNext = true;
                continue;
            }
            if (str_starts_with($argument, '--')) {
                continue;
            }

            return $argument;
        }

        $cwd = getcwd();
        return is_string($cwd) && $cwd !== '' ? basename($cwd) : null;
    }

    private function directory(): string
    {
        if ($this->hooksDirectory !== null) {
            return join_path($this->hooksDirectory, 'commands');
        }

        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            throw new \RuntimeException('Не удалось определить домашнюю директорию (HOME).');
        }

        return join_path($home, '.config', 'docker-cli', 'actions', 'hooks', 'commands');
    }
}
