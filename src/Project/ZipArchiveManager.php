<?php

declare(strict_types=1);

namespace DockerCli\Project;

use RuntimeException;
use ZipArchive;

final class ZipArchiveManager
{
    public function compress(string $sqlFile): string
    {
        $archiveFile = $sqlFile . '.zip';
        $archive = new ZipArchive();
        if ($archive->open($archiveFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException(sprintf('Не удалось создать архив "%s".', $archiveFile));
        }

        $added = $archive->addFile($sqlFile, basename($sqlFile));
        $closed = $archive->close();
        if (!$added || !$closed) {
            @unlink($archiveFile);
            throw new RuntimeException(sprintf('Не удалось добавить дамп в архив "%s".', $archiveFile));
        }

        if (!unlink($sqlFile)) {
            @unlink($archiveFile);
            throw new RuntimeException(sprintf('Не удалось удалить SQL-дамп "%s".', $sqlFile));
        }

        return $archiveFile;
    }

    /** @return list<string> */
    public function extractSqlFiles(string $archiveFile, string $directory): array
    {
        $archive = new ZipArchive();
        if ($archive->open($archiveFile) !== true) {
            throw new RuntimeException(sprintf('Не удалось открыть zip-архив "%s".', $archiveFile));
        }

        $entries = [];
        for ($index = 0; $index < $archive->numFiles; ++$index) {
            $name = $archive->getNameIndex($index);
            if (!is_string($name) || basename($name) !== $name || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'sql') {
                continue;
            }
            $entries[] = [$name, $index];
        }
        usort($entries, static fn (array $left, array $right): int => strcmp($left[0], $right[0]));

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            $archive->close();
            throw new RuntimeException(sprintf('Не удалось создать временную директорию "%s".', $directory));
        }

        $files = [];
        foreach ($entries as [$name, $index]) {
            $stream = $archive->getStreamIndex($index);
            $target = $directory . DIRECTORY_SEPARATOR . $name;
            if ($stream === false || file_put_contents($target, $stream) === false) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $archive->close();
                throw new RuntimeException(sprintf('Не удалось извлечь "%s" из архива "%s".', $name, $archiveFile));
            }
            fclose($stream);
            $files[] = $target;
        }
        $archive->close();

        return $files;
    }
}
