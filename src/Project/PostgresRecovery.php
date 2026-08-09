<?php

declare(strict_types=1);

namespace DockerCli\Project;

use Symfony\Component\Console\Output\OutputInterface;

final class PostgresRecovery
{
    /** @var null|\Closure(list<string>, bool): array{0: int, 1: string} */
    private readonly ?\Closure $runner;

    /** @param null|callable(list<string>, bool): array{0: int, 1: string} $runner */
    public function __construct(?callable $runner = null)
    {
        $this->runner = $runner === null ? null : \Closure::fromCallable($runner);
    }

    /** @param list<string> $requestedDatabases */
    public function recover(string $source, string $destination, array $requestedDatabases, OutputInterface $output): void
    {
        $version = $this->detectVersion($source);
        $this->prepareDestination($destination);
        $suffix = bin2hex(random_bytes(6));
        $container = 'docker-cli-postgres-recover-' . $suffix;
        $network = $container;
        $volume = $container;
        $role = 'docker_cli_recover_' . $suffix;

        $output->writeln(sprintf('<info>Обнаружен PostgreSQL %s. Создаётся изолированная копия данных.</info>', $version));
        try {
            $this->mustRun(['docker', 'volume', 'create', $volume], $output);
            $this->mustRun([
                'docker', 'run', '--rm', '--entrypoint', 'sh',
                '--volume', $source . ':/source:ro', '--volume', $volume . ':/target',
                'postgres:' . $version, '-ec', 'cp -a /source/. /target/ && chown -R postgres:postgres /target',
            ], $output);
            $this->mustRun([
                'docker', 'run', '--rm', '--user', 'postgres', '--entrypoint', 'sh',
                '--volume', $volume . ':/var/lib/postgresql/data', 'postgres:' . $version, '-ec',
                'printf \'CREATE ROLE "%s" SUPERUSER LOGIN;\\n\' "$1" | postgres --single -D /var/lib/postgresql/data template1',
                'sh', $role,
            ], $output);
            $this->mustRun(['docker', 'network', 'create', $network], $output);
            $this->mustRun([
                'docker', 'run', '--detach', '--name', $container, '--network', $network,
                '--volume', $volume . ':/var/lib/postgresql/data', '--entrypoint', 'sh',
                'postgres:' . $version, '-ec',
                "printf '%s\\n' 'local all all trust' 'host all all 0.0.0.0/0 trust' 'host all all ::/0 trust' >/tmp/pg_hba.conf; exec gosu postgres postgres -D /var/lib/postgresql/data -c listen_addresses='*' -c hba_file=/tmp/pg_hba.conf",
            ], $output);
            $this->waitUntilReady($container, $role);

            [, $databaseOutput] = $this->mustRun([
                'docker', 'exec', '--user', 'postgres', $container,
                'psql', '--no-password', '--tuples-only', '--no-align', '--username=' . $role, '--dbname=template1',
                '--command', "SELECT datname FROM pg_database WHERE datallowconn AND NOT datistemplate AND datname <> 'postgres' ORDER BY datname",
            ], $output, true);
            $available = array_values(array_filter(array_map('trim', preg_split('/\R/', $databaseOutput) ?: [])));
            $databases = $requestedDatabases === [] ? $available : $requestedDatabases;
            $missing = array_values(array_diff($databases, $available));
            if ($missing !== []) {
                throw new \InvalidArgumentException('В инстансе не найдены базы: ' . implode(', ', $missing) . '.');
            }
            if ($databases === []) {
                $output->writeln('<comment>Пользовательские базы данных не найдены.</comment>');
                return;
            }

            $timestamp = date('Ymd-His');
            foreach ($databases as $database) {
                $directory = $destination . DIRECTORY_SEPARATOR . $database . '-' . $timestamp;
                if (file_exists($directory)) {
                    throw new \RuntimeException(sprintf('Путь дампа уже существует: "%s".', $directory));
                }
                if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
                    throw new \RuntimeException(sprintf('Не удалось создать директорию "%s".', $directory));
                }
                $output->writeln(sprintf('<info>Экспорт базы "%s" в "%s".</info>', $database, $directory));
                $this->mustRun([
                    'docker', 'run', '--rm', '--network', $network, '--user', 'root',
                    '--volume', $directory . ':/dump', '--entrypoint', 'sh',
                    'postgres:' . $version, '-ec',
                    'database="$1"; jobs="$2"; uid="$3"; gid="$4"; pg_dump --host="$5" --username="$6" --format=directory --jobs="$jobs" --file=/dump "$database"; chown -R "$uid:$gid" /dump',
                    'sh', $database, '4', (string) $this->uid(), (string) $this->gid(), $container, $role,
                ], $output);
            }
        } finally {
            $this->run(['docker', 'rm', '--force', $container], false);
            $this->run(['docker', 'network', 'rm', $network], false);
            $this->run(['docker', 'volume', 'rm', '--force', $volume], false);
        }
    }

    public function detectVersion(string $source): string
    {
        if (!is_dir($source) || !is_file($source . '/PG_VERSION') || !is_dir($source . '/base') || !is_dir($source . '/global')) {
            throw new \InvalidArgumentException(sprintf('Директория "%s" не похожа на каталог data PostgreSQL.', $source));
        }
        $version = trim((string) @file_get_contents($source . '/PG_VERSION'));
        if (preg_match('/^[1-9]\d*(?:\.\d+)?$/', $version) !== 1) {
            throw new \InvalidArgumentException(sprintf('Файл PG_VERSION содержит неподдерживаемую версию: "%s".', $version));
        }
        return $version;
    }

    private function prepareDestination(string $destination): void
    {
        if (file_exists($destination) && !is_dir($destination)) {
            throw new \InvalidArgumentException(sprintf('Путь назначения "%s" не является директорией.', $destination));
        }
        if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
            throw new \RuntimeException(sprintf('Не удалось создать директорию "%s".', $destination));
        }
        if (!is_writable($destination)) {
            throw new \InvalidArgumentException(sprintf('Директория назначения "%s" недоступна для записи.', $destination));
        }
    }

    private function waitUntilReady(string $container, string $role): void
    {
        for ($attempt = 0; $attempt < 60; ++$attempt) {
            [$code] = $this->run(['docker', 'exec', '--user', 'postgres', $container, 'pg_isready', '--username=' . $role, '--dbname=template1'], true);
            if ($code === 0) return;
            usleep(500_000);
        }
        [, $logs] = $this->run(['docker', 'logs', $container], true);
        throw new \RuntimeException("Временный PostgreSQL не запустился.\n" . trim($logs));
    }

    /** @return array{0: int, 1: string} */
    private function mustRun(array $command, OutputInterface $output, bool $quiet = false): array
    {
        if (!$quiet) $output->writeln('<comment>' . implode(' ', array_map('escapeshellarg', $command)) . '</comment>');
        $result = $this->run($command, true);
        if ($result[0] !== 0) {
            throw new \RuntimeException(sprintf("Команда Docker завершилась с кодом %d:\n%s", $result[0], trim($result[1])));
        }
        return $result;
    }

    /** @return array{0: int, 1: string} */
    private function run(array $command, bool $capture): array
    {
        if ($this->runner !== null) return ($this->runner)($command, $capture);
        $pipes = [];
        $process = proc_open($command, [STDIN, ['pipe', 'w'], ['redirect', 1]], $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Не удалось запустить Docker.');
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        return [proc_close($process), (string) $stdout];
    }

    private function uid(): int { return function_exists('posix_getuid') ? posix_getuid() : 1000; }
    private function gid(): int { return function_exists('posix_getgid') ? posix_getgid() : 1000; }
}
