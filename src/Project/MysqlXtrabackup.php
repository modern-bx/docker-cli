<?php

declare(strict_types=1);

namespace DockerCli\Project;

use DockerCli\Config\SystemCompose;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

final class MysqlXtrabackup
{
    public function __construct(private readonly ?SystemCompose $compose = null) {}

    public function backup(string $directory, int $parallel, OutputInterface $output): int
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
            $output->writeln(sprintf('<error>Не удалось создать директорию "%s".</error>', $directory));
            return Command::FAILURE;
        }

        return $this->run(array_merge($compose->dockerComposeCommand('run'), [
            '--rm', '-T', '--no-deps', '--user', 'root', '--entrypoint', 'sh',
            '--volume', $directory . ':/backup', 'xtrabackup', '-ec',
            'xtrabackup --backup --host=mysql --user=root --password="${MYSQL_ROOT_PASSWORD:?}" --target-dir=/backup --parallel="$1" && chown -R "$2:$3" /backup',
            'sh', (string) $parallel, (string) $this->uid(), (string) $this->gid(),
        ]), $compose, $output);
    }

    public function restore(string $directory, int $parallel, OutputInterface $output): int
    {
        $compose = $this->compose ?? new SystemCompose();
        $compose->assertInitialized();

        $stopCode = $this->run(array_merge($compose->dockerComposeCommand('stop'), ['mysql']), $compose, $output);
        if ($stopCode !== Command::SUCCESS) {
            return $stopCode;
        }

        $restoreCode = $this->run(array_merge($compose->dockerComposeCommand('run'), [
            '--rm', '-T', '--no-deps', '--user', 'root', '--entrypoint', 'sh',
            '--volume', $directory . ':/backup', 'xtrabackup', '-ec',
            'xtrabackup --prepare --target-dir=/backup --parallel="$1" && find /var/lib/mysql -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + && xtrabackup --copy-back --target-dir=/backup --parallel="$1"',
            'sh', (string) $parallel,
        ]), $compose, $output);

        if ($restoreCode === Command::SUCCESS) {
            $restoreCode = $this->run(array_merge($compose->dockerComposeCommand('run'), [
                '--rm', '-T', '--no-deps', '--user', 'root', '--entrypoint', 'sh',
                'mysql', '-ec', 'chown -R mysql:mysql /var/lib/mysql',
            ]), $compose, $output);
        }

        $startCode = $this->run(array_merge($compose->dockerComposeCommand('up'), ['-d', 'mysql']), $compose, $output);
        return $restoreCode !== Command::SUCCESS ? $restoreCode : $startCode;
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
