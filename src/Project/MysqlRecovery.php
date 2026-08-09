<?php

declare(strict_types=1);

namespace DockerCli\Project;

use Symfony\Component\Console\Output\OutputInterface;

final class MysqlRecovery
{
    private const SYSTEM_DATABASES = ['information_schema', 'mysql', 'performance_schema', 'sys'];

    /** @var null|\Closure(list<string>, bool): array{0: int, 1: string} */
    private readonly ?\Closure $runner;

    /** @param null|callable(list<string>, bool): array{0: int, 1: string} $runner */
    public function __construct(?callable $runner = null)
    {
        $this->runner = $runner === null ? null : \Closure::fromCallable($runner);
    }

    public function recover(string $source, string $destination, array $requestedDatabases, OutputInterface $output): void
    {
        $version = $this->detectVersion($source);
        $this->prepareDestination($destination);
        $suffix = bin2hex(random_bytes(6));
        $container = 'docker-cli-mysql-recover-' . $suffix;
        $network = $container;
        $volume = $container;

        $output->writeln(sprintf('<info>Обнаружен MySQL %s. Создаётся изолированная копия данных.</info>', $version));
        try {
            $this->mustRun(['docker', 'volume', 'create', $volume], $output);
            $this->mustRun([
                'docker', 'run', '--rm', '--entrypoint', 'sh',
                '--volume', $source . ':/source:ro', '--volume', $volume . ':/target',
                'mysql:' . $version, '-ec', 'cp -a /source/. /target/ && chown -R mysql:mysql /target',
            ], $output);
            $this->mustRun(['docker', 'network', 'create', $network], $output);
            $this->mustRun([
                'docker', 'run', '--detach', '--name', $container, '--network', $network,
                '--volume', $volume . ':/var/lib/mysql', 'mysql:' . $version,
                '--skip-grant-tables', '--skip-networking=0', '--bind-address=0.0.0.0',
            ], $output);
            $this->waitUntilReady($container, $output);

            [, $databaseOutput] = $this->mustRun([
                'docker', 'exec', $container, 'mysql', '--batch', '--skip-column-names',
                '-e', 'SHOW DATABASES',
            ], $output, true);
            $available = array_values(array_diff(array_filter(array_map('trim', preg_split('/\R/', $databaseOutput) ?: [])), self::SYSTEM_DATABASES));
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
                    'mydumper/mydumper:v1.0.3-1', '-ec',
                    'mydumper --host="$1" --user=root --database="$2" --outputdir=/dump --threads=4 && chown -R "$3:$4" /dump',
                    'sh', $container, $database, (string) $this->uid(), (string) $this->gid(),
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
        if (!is_dir($source) || !is_file($source . '/ibdata1') || !is_dir($source . '/mysql')) {
            throw new \InvalidArgumentException(sprintf('Директория "%s" не похожа на каталог data MySQL.', $source));
        }
        $upgradeInfo = @file_get_contents($source . '/mysql_upgrade_info');
        if (is_string($upgradeInfo) && preg_match('/\b(5\.[567]|8\.0)\b/', $upgradeInfo, $match) === 1) {
            return $match[1];
        }
        if (is_file($source . '/mysql.ibd')) {
            return '8.0';
        }
        if (glob($source . '/mysql/*.frm') !== []) {
            return '5.7';
        }
        throw new \InvalidArgumentException('Не удалось определить поддерживаемую версию MySQL (5.5–5.7 или 8.0) по содержимому data.');
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

    private function waitUntilReady(string $container, OutputInterface $output): void
    {
        for ($attempt = 0; $attempt < 60; ++$attempt) {
            [$code] = $this->run(['docker', 'exec', $container, 'mysqladmin', 'ping', '--silent'], true);
            if ($code === 0) return;
            usleep(500_000);
        }
        [, $logs] = $this->run(['docker', 'logs', $container], true);
        throw new \RuntimeException("Временный MySQL не запустился.\n" . trim($logs));
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
