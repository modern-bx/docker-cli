<?php

declare(strict_types=1);

namespace DockerCli\Hook;

use function DockerCli\Util\join_path;

final readonly class HookJournal
{
    public function __construct(private ?string $configDirectory = null)
    {
    }

    /** @param array<string, mixed> $metadata */
    public function record(array $metadata, int $exitCode, string $stdout, string $stderr): void
    {
        $timestamp = $this->timestamp();
        $base = $metadata + [
            'type' => 'hook',
            'timestamp' => $timestamp,
            'result' => null,
        ];

        $this->append(array_merge($base, [
            'level' => $exitCode === 0 ? 'info' : 'error',
            'context' => 'hook',
            'stream' => 'exit',
            'result' => $exitCode,
            'message' => sprintf('Хук завершился с кодом %d.', $exitCode),
        ]));

        if ($stdout !== '') {
            $this->append(array_merge($base, [
                'level' => 'info',
                'context' => 'hook',
                'stream' => 'stdout',
                'message' => $stdout,
            ]));
        }

        if ($stderr !== '') {
            $this->append(array_merge($base, [
                'level' => $exitCode === 0 ? 'warning' : 'error',
                'context' => 'hook',
                'stream' => 'stderr',
                'message' => $stderr,
            ]));
        }
    }

    /** @return array{items: list<array<string, mixed>>, total: int, projects: list<string>} */
    public function logs(int $page, int $pageSize, string $sort, string $direction, array $projectsFilter = [], array $levels = [], ?string $command = null, ?string $hook = null, ?string $timing = null, ?string $hookLevel = null): array
    {
        $items = [];
        $projects = [];
        foreach (glob(join_path($this->journalDirectory(), '*.jsonl')) ?: [] as $file) {
            $handle = fopen($file, 'r');
            if ($handle === false) continue;
            while (($line = fgets($handle)) !== false) {
                try {
                    $record = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                    if (!is_array($record)) continue;
                    $recordProjects = $this->projectsFromRecord($record);
                    array_push($projects, ...$recordProjects);
                    if ($projectsFilter !== [] && array_intersect($projectsFilter, $recordProjects) === []) continue;
                    if ($levels !== [] && !in_array($record['level'] ?? null, $levels, true)) continue;
                    if ($command !== null && !str_contains(mb_strtolower((string) ($record['command'] ?? '')), mb_strtolower($command))) continue;
                    if ($hook !== null && !str_contains(mb_strtolower((string) ($record['hook'] ?? '')), mb_strtolower($hook))) continue;
                    if ($timing !== null && ($record['timing'] ?? null) !== $timing) continue;
                    if ($hookLevel !== null && ($record['hookLevel'] ?? null) !== $hookLevel) continue;
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

        return ['items' => array_slice($items, ($page - 1) * $pageSize, $pageSize), 'total' => count($items), 'projects' => $projects];
    }

    /** @param array<string, mixed> $record */
    private function append(array $record): void
    {
        $directory = $this->journalDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Не удалось создать директорию "%s".', $directory));
        }

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents(join_path($directory, 'default.jsonl'), $line, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось записать общий лог хуков.');
        }
    }

    private function journalDirectory(): string
    {
        return join_path($this->configDirectory(), 'journal', 'hooks');
    }

    private function configDirectory(): string
    {
        if ($this->configDirectory !== null) return $this->configDirectory;
        $home = getenv('HOME') ?: throw new \RuntimeException('HOME environment variable is not set.');
        return join_path($home, '.config', 'docker-cli');
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

    private function timestamp(): string
    {
        $microtime = microtime(true);
        $seconds = (int) $microtime;
        $fraction = (int) (($microtime - $seconds) * 1_000_000);
        return sprintf('%s.%06dZ', gmdate('Y-m-d\TH:i:s', $seconds), $fraction);
    }
}
