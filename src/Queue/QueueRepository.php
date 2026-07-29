<?php

declare(strict_types=1);

namespace DockerCli\Queue;

use Symfony\Component\Yaml\Yaml;
use function DockerCli\Util\join_path;

final class QueueRepository
{
    public const STATUSES = ['10-pending', '20-active', '30-success', '40-failure', '50-error'];

    public function __construct(private readonly ?string $configDirectory = null)
    {
    }

    public function configDirectory(): string
    {
        if ($this->configDirectory !== null) {
            return $this->configDirectory;
        }
        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            throw new \RuntimeException('Не удалось определить домашнюю директорию (HOME).');
        }

        return join_path($home, '.config', 'docker-cli');
    }

    public function queueDirectory(string $queue): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $queue) !== 1) {
            throw new \InvalidArgumentException(sprintf('Некорректный код очереди "%s".', $queue));
        }

        return join_path($this->configDirectory(), 'queue', $queue);
    }

    public function initialize(string $queue): void
    {
        foreach (self::STATUSES as $status) {
            $directory = join_path($this->queueDirectory($queue), $status);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Не удалось создать директорию "%s".', $directory));
            }
        }
        $logs = join_path($this->configDirectory(), 'logs', 'queue');
        if (!is_dir($logs) && !mkdir($logs, 0775, true) && !is_dir($logs)) {
            throw new \RuntimeException(sprintf('Не удалось создать директорию "%s".', $logs));
        }
    }

    public function isPaused(string $queue): bool
    {
        return is_file(join_path($this->queueDirectory($queue), '.pause'));
    }

    public function pause(string $queue): void
    {
        $this->initialize($queue);
        $file = join_path($this->queueDirectory($queue), '.pause');
        if (file_put_contents($file, '', LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Не удалось приостановить очередь "%s".', $queue));
        }
    }

    public function resume(string $queue): void
    {
        $file = join_path($this->queueDirectory($queue), '.pause');
        if (is_file($file) && !unlink($file)) {
            throw new \RuntimeException(sprintf('Не удалось возобновить очередь "%s".', $queue));
        }
    }

    public function nextPending(string $queue): ?string
    {
        $files = glob(join_path($this->queueDirectory($queue), '10-pending', '*.yaml')) ?: [];
        $files = array_values(array_filter($files, 'is_file'));
        $this->sortTimestampedFiles($files);

        return $files[0] ?? null;
    }

    /** @param array<string, mixed> $item */
    public function create(string $queue, string $code, array $item): string
    {
        $this->initialize($queue);
        $timestamp = (int) floor(microtime(true) * 1_000);
        $safeCode = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $code), '.-');
        $safeCode = $safeCode !== '' ? $safeCode : 'task';
        for ($counter = 0; $counter <= 999; ++$counter) {
            $file = join_path($this->queueDirectory($queue), '10-pending', sprintf('%d.%03d.%s.yaml', $timestamp, $counter, $safeCode));
            $handle = @fopen($file, 'x');
            if ($handle === false) {
                continue;
            }
            try {
                if (fwrite($handle, Yaml::dump($item, 8, 2)) === false) {
                    throw new \RuntimeException(sprintf('Не удалось записать элемент очереди "%s".', $file));
                }
            } catch (\Throwable $exception) {
                @unlink($file);
                throw $exception;
            } finally {
                fclose($handle);
            }
            return $file;
        }
        throw new \RuntimeException('Не удалось подобрать уникальное имя элемента очереди.');
    }

    /** @return list<array{file: string, status: string, queuedAt: string, code: string}> */
    public function items(string $queue): array
    {
        $items = [];
        foreach (['10-pending', '20-active', '40-failure', '50-error'] as $status) {
            $files = glob(join_path($this->queueDirectory($queue), $status, '*.yaml')) ?: [];
            foreach (array_filter($files, 'is_file') as $file) {
                $name = basename($file);
                $parts = explode('.', substr($name, 0, -5), 3);
                $microseconds = $this->timestampMicroseconds($parts[0] ?? '', $file);
                $items[] = [
                    'file' => $name,
                    'status' => $status,
                    'queuedAt' => sprintf('%s.%06dZ', gmdate('Y-m-d\TH:i:s', intdiv($microseconds, 1_000_000)), $microseconds % 1_000_000),
                    'code' => $parts[2] ?? pathinfo($name, PATHINFO_FILENAME),
                ];
            }
        }
        usort($items, static fn (array $left, array $right): int => [$left['queuedAt'], $left['file']] <=> [$right['queuedAt'], $right['file']]);

        return $items;
    }

    /**
     * @return list<array{queue: string, status: string, file: string, path: string, relativePath: string}>
     */
    public function listItems(?string $queue = null, ?string $status = null): array
    {
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf('Неизвестный статус "%s".', $status));
        }
        if ($queue !== null) {
            $directories = [$queue => $this->queueDirectory($queue)];
        } else {
            $directories = [];
            foreach (glob(join_path($this->configDirectory(), 'queue', '*'), GLOB_ONLYDIR) ?: [] as $directory) {
                $directories[basename($directory)] = $directory;
            }
            ksort($directories, SORT_STRING);
        }

        $items = [];
        foreach ($directories as $queueCode => $directory) {
            foreach ($status === null ? self::STATUSES : [$status] as $statusCode) {
                $files = glob(join_path($directory, $statusCode, '*.yaml')) ?: [];
                $this->sortTimestampedFiles($files);
                foreach (array_filter($files, 'is_file') as $file) {
                    $name = basename($file);
                    $items[] = [
                        'queue' => $queueCode,
                        'status' => $statusCode,
                        'file' => $name,
                        'path' => $file,
                        'relativePath' => join_path($queueCode, $statusCode, $name),
                    ];
                }
            }
        }

        return $items;
    }

    private function timestampMicroseconds(string $timestamp, string $file): int
    {
        if (!ctype_digit($timestamp)) {
            return ((int) filemtime($file)) * 1_000_000;
        }

        // Queue item names in the wild use Unix timestamps in seconds,
        // milliseconds, or microseconds. Normalize all three before formatting.
        return match (true) {
            strlen($timestamp) <= 10 => (int) $timestamp * 1_000_000,
            strlen($timestamp) <= 13 => (int) $timestamp * 1_000,
            default => (int) substr($timestamp, 0, 16),
        };
    }

    /** @param list<string> $files */
    private function sortTimestampedFiles(array &$files): void
    {
        usort($files, function (string $left, string $right): int {
            $leftTimestamp = explode('.', basename($left), 2)[0];
            $rightTimestamp = explode('.', basename($right), 2)[0];
            return [$this->timestampMicroseconds($leftTimestamp, $left), basename($left)]
                <=> [$this->timestampMicroseconds($rightTimestamp, $right), basename($right)];
        });
    }

    public function delete(string $queue, string $file): void
    {
        if (basename($file) !== $file || preg_match('/^[A-Za-z0-9._-]+\.yaml$/D', $file) !== 1) {
            throw new \InvalidArgumentException('Некорректное имя элемента очереди.');
        }
        if (is_file(join_path($this->queueDirectory($queue), '20-active', $file))) {
            throw new \RuntimeException('Нельзя удалить выполняющийся элемент очереди.');
        }
        foreach (array_diff(self::STATUSES, ['20-active']) as $status) {
            $path = join_path($this->queueDirectory($queue), $status, $file);
            if (is_file($path)) {
                if (!unlink($path)) throw new \RuntimeException('Не удалось удалить элемент очереди.');
                return;
            }
        }
        throw new \RuntimeException('Элемент очереди не найден.');
    }

    public function move(string $file, string $queue, string $status): string
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf('Неизвестный статус "%s".', $status));
        }
        $target = join_path($this->queueDirectory($queue), $status, basename($file));
        if (!rename($file, $target)) {
            throw new \RuntimeException(sprintf('Не удалось переместить "%s" в "%s".', $file, $target));
        }

        return $target;
    }

    /** @param array<string, mixed> $item */
    public function write(string $file, array $item): void
    {
        if (file_put_contents($file, Yaml::dump($item, 8, 2), LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Не удалось записать элемент очереди "%s".', $file));
        }
    }

    /** @param array<string, mixed> $item */
    public function trace(string $file, string $queue, array &$item, string $message, ?string $taskCode = null, ?string $project = null, ?int $result = null): void
    {
        $projects = $project !== null ? [$project] : $this->projectsFromItem($item, $taskCode);
        $project = $projects !== [] ? implode(', ', $projects) : null;
        $timestamp = sprintf('%.6f', microtime(true));
        while (isset($item['trace'][$timestamp])) {
            $timestamp = sprintf('%.6f', (float) $timestamp + 0.000001);
        }
        $item['trace'] ??= [];
        $item['trace'][$timestamp] = $message;
        $this->write($file, $item);
        $name = basename($file);
        $parts = explode('.', substr($name, 0, -5), 3);
        [$seconds, $fraction] = array_pad(explode('.', $timestamp, 2), 2, '0');
        $record = [
            'type' => 'queue',
            'timestamp' => sprintf('%s.%sZ', gmdate('Y-m-d\TH:i:s', (int) $seconds), str_pad(substr($fraction, 0, 6), 6, '0')),
            'queueItem' => $name,
            'itemCode' => $parts[2] ?? pathinfo($name, PATHINFO_FILENAME),
            'project' => $project,
            'projects' => $projects,
            'queueCode' => $queue,
            'status' => basename(dirname($file)),
            'taskCode' => $taskCode,
            'result' => $result,
            'message' => $message,
        ];
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents(join_path($this->configDirectory(), 'logs', 'queue', $queue . '.jsonl'), $line, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось записать общий лог очереди.');
        }
    }

    /** @param array<string, mixed> $item @return list<string> */
    private function projectsFromItem(array $item, ?string $taskCode): array
    {
        $projects = [];
        foreach ($item['queue-item']['tasks'] ?? [] as $task) {
            if (!is_array($task) || ($taskCode !== null && ($task['code'] ?? null) !== $taskCode)) {
                continue;
            }
            $project = $task['project'] ?? null;
            if (is_string($project) && $project !== '' && !in_array($project, $projects, true)) {
                $projects[] = $project;
            }
        }

        return $projects;
    }

    /** @param array<string, mixed> $record @return list<string> */
    private function projectsFromRecord(array $record): array
    {
        if (is_array($record['projects'] ?? null)) {
            return array_values(array_filter($record['projects'], static fn (mixed $project): bool => is_string($project) && $project !== ''));
        }
        $project = $record['project'] ?? null;

        return is_string($project) && $project !== '' ? [$project] : [];
    }

    /** @return array{items: list<array<string, mixed>>, total: int, projects: list<string>} */
    public function logs(int $page, int $pageSize, string $sort, string $direction, ?string $project, ?string $status = null, ?string $queueItem = null, ?string $itemCode = null, ?string $taskCode = null): array
    {
        $items = [];
        $projects = [];
        foreach (glob(join_path($this->configDirectory(), 'logs', 'queue', '*.jsonl')) ?: [] as $file) {
            $handle = fopen($file, 'r');
            if ($handle === false) continue;
            while (($line = fgets($handle)) !== false) {
                try {
                    $record = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                    if (!is_array($record)) continue;
                    $recordProjects = $this->projectsFromRecord($record);
                    array_push($projects, ...$recordProjects);
                    if ($project !== null && !in_array($project, $recordProjects, true)) continue;
                    if ($status !== null && ($record['status'] ?? null) !== $status) continue;
                    if ($queueItem !== null && !str_contains(mb_strtolower((string) ($record['queueItem'] ?? '')), mb_strtolower($queueItem))) continue;
                    if ($itemCode !== null && !str_contains(mb_strtolower((string) ($record['itemCode'] ?? '')), mb_strtolower($itemCode))) continue;
                    if ($taskCode !== null && !str_contains(mb_strtolower((string) ($record['taskCode'] ?? '')), mb_strtolower($taskCode))) continue;
                    $items[] = $record;
                } catch (\JsonException) {
                    // A partially written line must not make the complete log unavailable.
                }
            }
            fclose($handle);
        }
        $projects = array_values(array_unique($projects));
        sort($projects, SORT_NATURAL | SORT_FLAG_CASE);
        usort($items, static function (array $left, array $right) use ($sort, $direction): int {
            $result = ($left[$sort] ?? null) <=> ($right[$sort] ?? null);
            return $direction === 'asc' ? $result : -$result;
        });
        $total = count($items);
        return ['items' => array_slice($items, ($page - 1) * $pageSize, $pageSize), 'total' => $total, 'projects' => $projects];
    }
}
