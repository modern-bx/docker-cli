<?php

declare(strict_types=1);

namespace DockerCli\Hook;

use Symfony\Component\Console\Command\Command;
use function DockerCli\Util\join_path;

final class CommandHookRunner
{
    public function __construct(private readonly ?string $hooksDirectory = null)
    {
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
            if ($name !== '.' && $name !== '..' && is_file($file) && is_executable($file)) {
                $hooks[] = $file;
            }
        }
        sort($hooks, SORT_STRING);

        foreach ($hooks as $hook) {
            $process = proc_open(
                [$hook, 'hook:command', $command . ':' . $timing, ...$arguments],
                [STDIN, STDOUT, STDERR],
                $pipes,
                getcwd() ?: null,
            );
            if (!is_resource($process)) {
                throw new \RuntimeException(sprintf('Не удалось запустить хук "%s".', $hook));
            }

            $exitCode = proc_close($process);
            if ($exitCode !== Command::SUCCESS) {
                return $exitCode;
            }
        }

        return Command::SUCCESS;
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
