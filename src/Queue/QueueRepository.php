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

    public function nextPending(string $queue): ?string
    {
        $files = glob(join_path($this->queueDirectory($queue), '10-pending', '*.yaml')) ?: [];
        $files = array_values(array_filter($files, 'is_file'));
        sort($files, SORT_STRING);

        return $files[0] ?? null;
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
    public function trace(string $file, string $queue, array &$item, string $message): void
    {
        $timestamp = sprintf('%.6f', microtime(true));
        while (isset($item['trace'][$timestamp])) {
            $timestamp = sprintf('%.6f', (float) $timestamp + 0.000001);
        }
        $item['trace'] ??= [];
        $item['trace'][$timestamp] = $message;
        $this->write($file, $item);
        $line = sprintf("[%s] %s %s\n", $timestamp, basename($file), $message);
        if (file_put_contents(join_path($this->configDirectory(), 'logs', 'queue', $queue . '.log'), $line, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось записать общий лог очереди.');
        }
    }
}
