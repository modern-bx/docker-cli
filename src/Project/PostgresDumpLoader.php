<?php

declare(strict_types=1);

namespace DockerCli\Project;

use DockerCli\Config\SystemCompose;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

final class PostgresDumpLoader
{
    public function __construct(private readonly ?SystemCompose $compose = null) {}

    /** @param list<string> $include @param list<string> $exclude */
    public function dump(string $database, string $directory, int $jobs, OutputInterface $output, array $include = [], array $exclude = []): int
    {
        $compose = $this->compose ?? new SystemCompose();
        $compose->assertInitialized();

        if (file_exists($directory) && !is_dir($directory)) {
            $output->writeln(sprintf('<error>Путь "%s" уже существует и не является директорией.</error>', $directory));
            return Command::FAILURE;
        }
        if (is_dir($directory) && (new \FilesystemIterator($directory))->valid()) {
            $output->writeln(sprintf('<error>Директория бэкапа "%s" не пуста.</error>', $directory));
            return Command::FAILURE;
        }
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            $output->writeln(sprintf('<error>Не удалось создать директорию бэкапа "%s".</error>', $directory));
            return Command::FAILURE;
        }

        $filters = [];
        foreach ($include as $pattern) $filters[] = '--table=' . $pattern;
        foreach ($exclude as $pattern) $filters[] = '--exclude-table=' . $pattern;
        return $this->run(array_merge($compose->dockerComposeCommand('run'), [
            '--rm', '-T', '--no-deps', '--user', 'root', '--entrypoint', 'sh',
            '--volume', $directory . ':/dump', 'postgres', '-ec',
            'export PGPASSWORD="${POSTGRES_PASSWORD:?}"; database="$1"; jobs="$2"; uid="$3"; gid="$4"; shift 4; pg_dump --host=postgres --username="${POSTGRES_USER:-system}" --format=directory --jobs="$jobs" --file=/dump "$database" "$@"; chown -R "$uid:$gid" /dump',
            'sh', $database, (string) $jobs, (string) $this->uid(), (string) $this->gid(), ...$filters,
        ]), $compose, $output);
    }

    public function load(string $database, string $owner, string $directory, int $jobs, OutputInterface $output): int
    {
        $compose = $this->compose ?? new SystemCompose();
        $compose->assertInitialized();

        return $this->run(array_merge($compose->dockerComposeCommand('run'), [
            '--rm', '-T', '--no-deps', '--entrypoint', 'sh',
            '--volume', $directory . ':/dump:ro', 'postgres', '-ec',
            <<<'SH'
set -eu
export PGPASSWORD="${POSTGRES_PASSWORD:?}"
root_user="${POSTGRES_USER:-system}"
dropdb --host=postgres --username="$root_user" --if-exists --force "$1"
createdb --host=postgres --username="$root_user" --owner="$2" "$1"
pg_restore --host=postgres --username="$root_user" --dbname="$1" --jobs="$3" --no-owner --no-acl --role="$2" /dump
SH,
            'sh', $database, $owner, (string) $jobs,
        ]), $compose, $output);
    }

    /** @param list<string> $command */
    private function run(array $command, SystemCompose $compose, OutputInterface $output): int
    {
        $output->writeln('<comment>' . implode(' ', array_map('escapeshellarg', $command)) . '</comment>');
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, $compose->dockerProcessEnvironment());
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start Docker Compose process.');
        }

        return proc_close($process);
    }

    private function uid(): int
    {
        return function_exists('posix_getuid') ? posix_getuid() : 1000;
    }

    private function gid(): int
    {
        return function_exists('posix_getgid') ? posix_getgid() : 1000;
    }
}
