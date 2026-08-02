<?php

declare(strict_types=1);

namespace DockerCli\Project;

use function DockerCli\Util\join_path;

final readonly class TreeArchiveLoader
{
    public function __construct(private ?TreeArchiveManager $manager = null) {}

    public function load(string $backupDirectory, string $projectRoot, bool $force, bool $wipe): void
    {
        $metadata = json_decode((string) @file_get_contents(join_path($backupDirectory, 'docker-cli.json')), true);
        if (!is_array($metadata)) throw new \InvalidArgumentException('Метаданные файлового бэкапа повреждены.');
        $volumes = new TreeArchiveVolumes();
        $archive = $volumes->assemble($backupDirectory, $metadata);
        $temporary = isset($metadata['volumes']);
        try {
            $entries = $this->entries($archive, (string) ($metadata['archive'] ?? $archive));
            foreach ($entries as $entry) $this->assertSafeEntry($entry);

            if ($wipe) ($this->manager ?? new TreeArchiveManager())->wipeProject($projectRoot);
            if (!$force) {
                foreach ($entries as $entry) {
                    $relative = rtrim($entry, '/');
                    if ($relative === '' || $relative === '.') continue;
                    $target = join_path($projectRoot, $relative);
                    $isDirectory = str_ends_with($entry, '/');
                    if (($isDirectory && file_exists($target) && !is_dir($target))
                        || (!$isDirectory && (file_exists($target) || is_link($target)))) {
                        throw new \InvalidArgumentException(sprintf('Файл «%s» уже существует. Используйте --force для перезаписи.', $relative));
                    }
                }
            }
            $this->extract($archive, $projectRoot, (string) ($metadata['archive'] ?? $archive));
        } finally { if ($temporary) @unlink($archive); }
    }

    /** @return list<string> */
    private function entries(string $archive, string $archiveName): array
    {
        $command = $this->tarCommand($archive, $archiveName, '-tf');
        $output = $this->run($command, true);
        return array_values(array_filter(explode("\n", rtrim($output, "\n")), static fn (string $entry): bool => $entry !== ''));
    }

    private function extract(string $archive, string $projectRoot, string $archiveName): void
    {
        $command = $this->tarCommand($archive, $archiveName, '-xf', ['-C', $projectRoot]);
        $this->run($command);
    }

    /** @param list<string> $suffix @return list<string> */
    private function tarCommand(string $archive, string $archiveName, string $operation, array $suffix = []): array
    {
        if (str_ends_with($archiveName, '.tar.lz4')) {
            return ['sh', '-c', 'lz4 -dc -- ' . escapeshellarg($archive) . ' | tar ' . $operation . ' - ' . implode(' ', array_map('escapeshellarg', $suffix))];
        }
        if (str_ends_with($archiveName, '.tar.zip')) {
            return ['sh', '-c', 'unzip -p ' . escapeshellarg($archive) . ' tree.tar | tar ' . $operation . ' - ' . implode(' ', array_map('escapeshellarg', $suffix))];
        }
        return ['tar', $operation, $archive, ...$suffix];
    }

    private function assertSafeEntry(string $entry): void
    {
        $normalized = str_replace('\\', '/', $entry);
        $normalized = preg_replace('~^\./~', '', $normalized) ?? $normalized;
        if ($normalized === '') return;
        if (str_starts_with($normalized, '/')
            || in_array('..', explode('/', rtrim($normalized, '/')), true)
            || $normalized === '.docker-cli' || str_starts_with($normalized, '.docker-cli/')) {
            throw new \InvalidArgumentException(sprintf('Архив содержит небезопасный путь «%s».', $entry));
        }
    }

    /** @param list<string> $command */
    private function run(array $command, bool $capture = false): string
    {
        $descriptors = [STDIN, $capture ? ['pipe', 'w'] : STDOUT, STDERR];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Не удалось запустить распаковку файлового бэкапа.');
        $output = $capture ? stream_get_contents($pipes[1]) : '';
        if ($capture) fclose($pipes[1]);
        $code = proc_close($process);
        if ($code !== 0) throw new \RuntimeException(sprintf('Распаковка файлового бэкапа завершилась с кодом %d.', $code));
        return is_string($output) ? $output : '';
    }
}
