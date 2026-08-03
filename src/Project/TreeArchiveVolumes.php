<?php

declare(strict_types=1);

namespace DockerCli\Project;

use function DockerCli\Util\join_path;

final class TreeArchiveVolumes
{
    public static function parseSize(string $value): int
    {
        if (preg_match('/^(\d+(?:\.\d+)?)(B|K|M|G)?$/i', trim($value), $matches) !== 1) {
            throw new \InvalidArgumentException('Размер тома должен иметь формат 1024, 1024B, 10K, 1.5M или 2.25G.');
        }
        $multiplier = match (strtoupper($matches[2] ?? '')) {
            '', 'B' => 1, 'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3,
        };
        $bytes = (float) $matches[1] * $multiplier;
        if ($bytes < 1 || $bytes > PHP_INT_MAX || floor($bytes) !== $bytes) {
            throw new \InvalidArgumentException('Размер тома должен задавать целое положительное количество байт.');
        }
        return (int) $bytes;
    }

    /** @return array{chunkSize: int, chunkCount: int, parts: list<array{name: string, size: int}>} */
    public function split(string $directory, string $archiveName, ?int $chunkSize, ?int $chunkCount): array
    {
        $archive = join_path($directory, $archiveName);
        $total = filesize($archive);
        if ($total === false) throw new \RuntimeException('Не удалось определить размер файлового архива.');
        if ($chunkCount !== null) {
            if ($chunkCount < 2) throw new \InvalidArgumentException('Количество томов должно быть не меньше 2.');
            if ($total < $chunkCount) throw new \InvalidArgumentException('Количество томов не может превышать размер архива в байтах.');
            $chunkSize = (int) ceil($total / $chunkCount);
        }
        if ($chunkSize === null || $chunkSize < 1) throw new \InvalidArgumentException('Размер тома должен быть положительным.');

        $source = fopen($archive, 'rb');
        if (!is_resource($source)) throw new \RuntimeException('Не удалось открыть файловый архив для разбиения на тома.');
        $parts = [];
        try {
            for ($index = 1; !feof($source); $index++) {
                $name = sprintf('%s.part%03d', $archiveName, $index);
                $target = fopen(join_path($directory, $name), 'wb');
                if (!is_resource($target)) throw new \RuntimeException(sprintf('Не удалось создать том «%s».', $name));
                $written = stream_copy_to_stream($source, $target, $chunkSize);
                fclose($target);
                if ($written === false) throw new \RuntimeException(sprintf('Не удалось записать том «%s».', $name));
                if ($written === 0) { @unlink(join_path($directory, $name)); break; }
                $parts[] = ['name' => $name, 'size' => $written];
            }
        } finally {
            fclose($source);
        }
        if ($chunkCount !== null && count($parts) !== $chunkCount) throw new \RuntimeException('Не удалось создать запрошенное количество томов.');
        if (!unlink($archive)) throw new \RuntimeException('Не удалось удалить исходный архив после разбиения на тома.');
        return ['chunkSize' => $chunkSize, 'chunkCount' => count($parts), 'parts' => $parts];
    }

    /** @param array<string, mixed> $metadata @return list<string> */
    public function validate(string $directory, array $metadata): array
    {
        $archive = $metadata['archive'] ?? null;
        if (!is_string($archive) || preg_match('/^tree\.tar(?:\.(?:gz|bz2|xz|zst|lz4|zip))?$/', $archive) !== 1) {
            return ['В метаданных отсутствует корректное имя файлового архива.'];
        }
        $volumes = $metadata['volumes'] ?? null;
        if ($volumes === null) return is_file(join_path($directory, $archive)) ? [] : [sprintf('Отсутствует файл архива «%s».', $archive)];
        if (!is_array($volumes) || !is_int($volumes['chunkCount'] ?? null) || !is_int($volumes['chunkSize'] ?? null)
            || !is_array($volumes['parts'] ?? null) || !array_is_list($volumes['parts'])) {
            return ['Метаданные о томах повреждены или имеют неизвестный формат.'];
        }
        $errors = [];
        $parts = $volumes['parts'];
        if (count($parts) !== $volumes['chunkCount']) $errors[] = sprintf('В метаданных перечислено %d частей, но указано количество %d.', count($parts), $volumes['chunkCount']);
        foreach ($parts as $index => $part) {
            if (!is_array($part) || !is_string($part['name'] ?? null) || basename($part['name']) !== $part['name'] || !is_int($part['size'] ?? null)) {
                $errors[] = sprintf('Описание части №%d в метаданных повреждено.', $index + 1);
                continue;
            }
            $path = join_path($directory, $part['name']);
            if (!is_file($path)) { $errors[] = sprintf('Не хватает части №%d «%s».', $index + 1, $part['name']); continue; }
            $actual = filesize($path);
            if ($actual !== $part['size']) $errors[] = sprintf('Размер части №%d «%s» не совпадает: ожидалось %d байт, найдено %d байт.', $index + 1, $part['name'], $part['size'], $actual === false ? 0 : $actual);
            if ($index < count($parts) - 1 && $part['size'] !== $volumes['chunkSize']) $errors[] = sprintf('Размер части №%d не равен указанному размеру тома %d байт.', $index + 1, $volumes['chunkSize']);
        }
        $expectedNames = array_filter(array_column($parts, 'name'), 'is_string');
        foreach (glob(join_path($directory, $archive . '.part*')) ?: [] as $path) {
            if (!in_array(basename($path), $expectedNames, true)) $errors[] = sprintf('Найдена лишняя часть «%s», отсутствующая в метаданных.', basename($path));
        }
        return array_values(array_unique($errors));
    }

    /** @param array<string, mixed> $metadata */
    public function assemble(string $directory, array $metadata): string
    {
        $errors = $this->validate($directory, $metadata);
        if ($errors !== []) throw new \InvalidArgumentException("Файловый бэкап повреждён:\n— " . implode("\n— ", $errors));
        if (!isset($metadata['volumes'])) return join_path($directory, $metadata['archive']);
        $temporary = tempnam(sys_get_temp_dir(), 'docker-cli-tree-volume-');
        if ($temporary === false) throw new \RuntimeException('Не удалось создать временный файл для сборки томов.');
        $target = fopen($temporary, 'wb');
        try {
            if (!is_resource($target)) throw new \RuntimeException('Не удалось открыть временный файл для сборки томов.');
            foreach ($metadata['volumes']['parts'] as $part) {
                $source = fopen(join_path($directory, $part['name']), 'rb');
                if (!is_resource($source) || stream_copy_to_stream($source, $target) === false) throw new \RuntimeException(sprintf('Не удалось прочитать том «%s».', $part['name']));
                fclose($source);
            }
            fclose($target);
            return $temporary;
        } catch (\Throwable $exception) {
            if (is_resource($target)) fclose($target);
            @unlink($temporary);
            throw $exception;
        }
    }
}
