<?php

declare(strict_types=1);

namespace DockerCli\Notification;

use Symfony\Component\Yaml\Yaml;
use function DockerCli\Util\join_path;

final class NotificationRepository
{
    public const LEVELS = ['info', 'warn', 'error', 'debug'];

    public function __construct(private readonly ?string $configDirectory = null)
    {
    }

    public function initialize(): void
    {
        foreach (['current', 'archive'] as $status) {
            $directory = $this->directory($status);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Не удалось создать директорию уведомлений "%s".', $directory));
            }
        }
    }

    public function create(string $origin, string $class, string $level, string $message, ?\DateTimeImmutable $time = null): string
    {
        if (!in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException(sprintf('Неизвестный уровень уведомления "%s".', $level));
        }
        $this->initialize();
        $time ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $timestamp = (int) floor(microtime(true) * 1_000_000);
        $code = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $origin), '.-') ?: 'notification';
        $data = [
            'meta' => ['schema' => 'notification', 'version' => 0.1],
            'notification' => ['time' => $time->format('Y-m-d\TH:i:s.uP'), 'origin' => $origin, 'class' => $class, 'level' => $level, 'message' => $message],
        ];
        for ($counter = 0; $counter <= 999; ++$counter) {
            $file = join_path($this->directory('current'), sprintf('%d.%03d.%s.yaml', $timestamp, $counter, $code));
            $handle = @fopen($file, 'x');
            if ($handle === false) continue;
            try {
                if (fwrite($handle, Yaml::dump($data, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)) === false) {
                    throw new \RuntimeException('Не удалось записать уведомление.');
                }
            } catch (\Throwable $exception) {
                @unlink($file);
                throw $exception;
            } finally {
                fclose($handle);
            }
            return basename($file);
        }
        throw new \RuntimeException('Не удалось подобрать уникальное имя уведомления.');
    }

    /** @return list<array{file: string, time: string, origin: string, class: string, level: string, message: string}> */
    public function current(): array
    {
        $this->initialize();
        $result = [];
        foreach (glob(join_path($this->directory('current'), '*.yaml')) ?: [] as $file) {
            if (!is_file($file)) continue;
            try {
                $document = Yaml::parseFile($file);
            } catch (\Throwable) {
                continue;
            }
            $data = is_array($document)
                && ($document['meta']['schema'] ?? null) === 'notification'
                && ($document['meta']['version'] ?? null) === 0.1
                && is_array($document['notification'] ?? null)
                ? $document['notification'] : null;
            if (!is_array($data) || !is_string($data['time'] ?? null) || !is_string($data['message'] ?? null)
                || !in_array($data['level'] ?? null, self::LEVELS, true)) continue;
            $result[] = [
                'file' => basename($file), 'time' => $data['time'],
                'origin' => is_string($data['origin'] ?? null) ? $data['origin'] : '',
                'class' => is_string($data['class'] ?? null) ? $data['class'] : '',
                'level' => $data['level'], 'message' => $data['message'],
            ];
        }
        usort($result, static fn (array $left, array $right): int => [$right['time'], $right['file']] <=> [$left['time'], $left['file']]);
        return $result;
    }

    public function archive(string $file): void
    {
        if (basename($file) !== $file || preg_match('/^[A-Za-z0-9._-]+\.yaml$/D', $file) !== 1) {
            throw new \InvalidArgumentException('Некорректное имя уведомления.');
        }
        $this->initialize();
        $source = join_path($this->directory('current'), $file);
        if (!is_file($source)) throw new \RuntimeException('Уведомление не найдено.');
        if (!rename($source, join_path($this->directory('archive'), $file))) {
            throw new \RuntimeException('Не удалось архивировать уведомление.');
        }
    }

    public function archiveAll(): void
    {
        $this->initialize();
        foreach (glob(join_path($this->directory('current'), '*.yaml')) ?: [] as $source) {
            if (!is_file($source)) continue;
            $target = join_path($this->directory('archive'), basename($source));
            if (!rename($source, $target)) {
                throw new \RuntimeException('Не удалось архивировать все уведомления.');
            }
        }
    }

    private function directory(string $status): string
    {
        $root = $this->configDirectory;
        if ($root === null) {
            $home = getenv('HOME');
            if (!is_string($home) || $home === '') throw new \RuntimeException('Не удалось определить домашнюю директорию (HOME).');
            $root = join_path($home, '.config', 'docker-cli');
        }
        return join_path($root, 'notifications', $status);
    }
}
