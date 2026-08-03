<?php

declare(strict_types=1);

namespace DockerCli\Project;

use DockerCli\Config\SystemCompose;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

final class MysqlDumpLoader
{
    public function __construct(private readonly ?SystemCompose $compose = null) {}

    /** @param list<string> $include @param list<string> $exclude */
    public function dump(string $database, string $directory, int $threads, OutputInterface $output, array $include = [], array $exclude = []): int
    {
        $compose = $this->compose ?? new SystemCompose();
        $compose->assertInitialized();

        if (file_exists($directory) && !is_dir($directory)) {
            $output->writeln(sprintf('<error>Путь "%s" уже существует и не является директорией.</error>', $directory));
            return Command::FAILURE;
        }
        if (is_dir($directory) && (new \FilesystemIterator($directory))->valid()) {
            $output->writeln(sprintf('<error>Директория дампа "%s" не пуста.</error>', $directory));
            return Command::FAILURE;
        }
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            $output->writeln(sprintf('<error>Не удалось создать директорию "%s".</error>', $directory));
            return Command::FAILURE;
        }

        $regex = $this->strategyRegex($database, $include, $exclude);
        return $this->run(array_merge($compose->dockerComposeCommand('run'), [
            '--rm', '-T', '--no-deps', '--user', 'root', '--entrypoint', 'sh',
            '--volume', $directory . ':/dump', 'mydumper', '-ec',
            'mydumper --host=mysql --user=root --password="${MYSQL_ROOT_PASSWORD:?}" --database="$1" --outputdir=/dump --threads="$2" ${5:+--regex="$5"} && chown -R "$3:$4" /dump',
            'sh', $database, (string) $threads, (string) $this->uid(), (string) $this->gid(), $regex,
        ]), $compose, $output);
    }

    /** @param list<string> $include @param list<string> $exclude */
    private function strategyRegex(string $database, array $include, array $exclude): string
    {
        $glob = static fn (string $value): string => str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($value, '/'));
        $prefix = '^' . preg_quote($database, '/') . '\\.';
        $allowed = $include === [] ? '.*' : '(?:' . implode('|', array_map($glob, $include)) . ')';
        $denied = $exclude === [] ? '' : '(?!(?:' . implode('|', array_map($glob, $exclude)) . ')$)';
        return $include === [] && $exclude === [] ? '' : $prefix . $denied . $allowed . '$';
    }

    public function load(string $database, string $directory, int $threads, bool $disableRedoLog, OutputInterface $output): int
    {
        $compose = $this->compose ?? new SystemCompose();
        $compose->assertInitialized();
        $redoOption = $disableRedoLog ? '--disable-redo-log' : '';

        return $this->run(array_merge($compose->dockerComposeCommand('run'), [
            '--rm', '-T', '--no-deps', '--entrypoint', 'sh',
            '--volume', $directory . ':/dump:ro', 'mydumper', '-ec',
            'myloader --host=mysql --user=root --password="${MYSQL_ROOT_PASSWORD:?}" --database="$1" --directory=/dump --threads="$2" --drop-database --optimize-keys=AFTER_IMPORT_ALL_TABLES $3',
            'sh', $database, (string) $threads, $redoOption,
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
