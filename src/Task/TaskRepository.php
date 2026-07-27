<?php

declare(strict_types=1);

namespace DockerCli\Task;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use function DockerCli\Util\join_path;

final class TaskRepository
{
    public function __construct(private readonly ?string $directory = null)
    {
    }

    public function directory(): string
    {
        if ($this->directory !== null) {
            return $this->directory;
        }

        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            throw new \RuntimeException('Не удалось определить домашнюю директорию (HOME).');
        }

        return join_path($home, '.config', 'docker-cli', 'tasks');
    }

    /** @return array{file: string, task: array<string, mixed>} */
    public function find(string $code): array
    {
        foreach ($this->files() as $file) {
            try {
                $document = Yaml::parseFile($file);
            } catch (ParseException $exception) {
                throw new \RuntimeException(sprintf('Ошибка YAML в "%s": %s', $file, $exception->getMessage()), 0, $exception);
            }

            if (!is_array($document) || ($document['task']['code'] ?? null) !== $code) {
                continue;
            }
            if (($document['meta']['schema'] ?? null) !== 'task' || (string) ($document['meta']['version'] ?? '') !== '0.1') {
                throw new \RuntimeException(sprintf('Задача "%s" должна иметь meta.schema=task и meta.version=0.1.', $code));
            }
            if (!is_array($document['task'])) {
                throw new \RuntimeException(sprintf('Некорректный блок task в "%s".', $file));
            }

            return ['file' => $file, 'task' => $document['task']];
        }

        throw new \RuntimeException(sprintf('Задача с кодом "%s" не найдена в "%s".', $code, $this->directory()));
    }

    /** @return list<string> */
    private function files(): array
    {
        $directory = $this->directory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Не удалось создать директорию задач "%s".', $directory));
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), ['yaml', 'yml'], true)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }
}
