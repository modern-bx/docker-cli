<?php

declare(strict_types=1);

namespace DockerCli\Project;

use function DockerCli\Util\join_path;

final class TreeArchiveManager
{
    /** @var array<string, string> */
    private const EXTENSIONS = [
        'gzip' => 'gz', 'gz' => 'gz',
        'bzip2' => 'bz2', 'bz2' => 'bz2',
        'xz' => 'xz',
        'zstd' => 'zst', 'zst' => 'zst',
        'lz4' => 'lz4',
        'zip' => 'zip',
    ];

    /** @param list<string> $include @param list<string> $exclude */
    public function dump(string $projectRoot, string $backupDirectory, ?string $compressor, array $include = [], array $exclude = []): string
    {
        $projectRoot = realpath($projectRoot) ?: throw new \InvalidArgumentException('Корневая директория проекта не существует.');
        $compressor = $compressor === null ? null : strtolower($compressor);
        if ($compressor !== null && !isset(self::EXTENSIONS[$compressor])) {
            throw new \InvalidArgumentException('Поддерживаемые архиваторы: gzip, bzip2, xz, zstd, lz4, zip.');
        }
        if (file_exists($backupDirectory)) {
            throw new \InvalidArgumentException(sprintf('Бэкап «%s» уже существует.', basename($backupDirectory)));
        }
        if (!mkdir($backupDirectory, 0775, true) && !is_dir($backupDirectory)) {
            throw new \RuntimeException(sprintf('Не удалось создать директорию бэкапа «%s».', $backupDirectory));
        }

        $tar = join_path($backupDirectory, 'tree.tar');
        $manifest = null;
        try {
            if ($include !== [] || $exclude !== []) $manifest = $this->createManifest($projectRoot, $include, $exclude);
            if ($compressor !== null && $compressor !== 'zip') {
                $archive = $tar . '.' . self::EXTENSIONS[$compressor];
                $this->streamCompressedTar($projectRoot, $archive, $compressor, $manifest);
                return basename($archive);
            }

            $this->createTar($projectRoot, $tar, $manifest);
            if ($compressor === null) return basename($tar);

            $archive = $this->compress($tar, $compressor);
            return basename($archive);
        } catch (\Throwable $exception) {
            $this->removeDirectory($backupDirectory);
            throw $exception;
        } finally {
            if ($manifest !== null) @unlink($manifest);
        }
    }

    private function createTar(string $projectRoot, string $tar, ?string $manifest): void
    {
        $command = ['tar', '-cf', $tar, '-C', $projectRoot];
        if ($manifest !== null) array_push($command, '--null', '--verbatim-files-from', '--no-recursion', '-T', $manifest);
        else array_push($command, '--exclude=./.docker-cli', '.');
        $this->run($command);
    }

    private function streamCompressedTar(string $projectRoot, string $archive, string $compressor, ?string $manifest): void
    {
        $tarCommand = ['tar', '-cf', '-', '-C', $projectRoot];
        if ($manifest !== null) array_push($tarCommand, '--null', '--verbatim-files-from', '--no-recursion', '-T', $manifest);
        else array_push($tarCommand, '--exclude=./.docker-cli', '.');
        $compressCommand = match ($compressor) {
            'gzip', 'gz' => [$this->executable('pigz') ? 'pigz' : 'gzip', '-1', '-c'],
            'bzip2', 'bz2' => [$this->executable('pbzip2') ? 'pbzip2' : 'bzip2', '-1', '-c'],
            'xz' => ['xz', '-0', '-T0', '-c'],
            'zstd', 'zst' => ['zstd', '-1', '-T0', '-q', '-c'],
            'lz4' => ['lz4', '-1', '-q', '-c'],
        };
        if (!$this->executable('tar')) throw new \RuntimeException('Команда «tar» не найдена.');
        if (!$this->executable($compressCommand[0])) throw new \RuntimeException(sprintf('Команда «%s» не найдена.', $compressCommand[0]));

        $target = fopen($archive, 'wb');
        if (!is_resource($target)) throw new \RuntimeException('Не удалось создать файл архива.');
        $tar = proc_open($tarCommand, [STDIN, ['pipe', 'w'], STDERR], $tarPipes);
        if (!is_resource($tar)) {
            fclose($target);
            throw new \RuntimeException('Не удалось запустить команду «tar».');
        }
        $compress = proc_open($compressCommand, [$tarPipes[1], $target, STDERR], $compressPipes);
        fclose($tarPipes[1]);
        fclose($target);
        if (!is_resource($compress)) {
            proc_terminate($tar);
            proc_close($tar);
            throw new \RuntimeException(sprintf('Не удалось запустить команду «%s».', $compressCommand[0]));
        }
        $compressCode = proc_close($compress);
        $tarCode = proc_close($tar);
        if ($tarCode !== 0 || $compressCode !== 0) {
            throw new \RuntimeException(sprintf('Архивация завершилась с ошибкой (tar: %d, %s: %d).', $tarCode, $compressCommand[0], $compressCode));
        }
    }

    /** @param list<string> $include @param list<string> $exclude */
    private function createManifest(string $projectRoot, array $include, array $exclude): string
    {
        $paths = $this->selectedPaths($projectRoot, $include, $exclude);
        $manifest = tempnam(sys_get_temp_dir(), 'docker-cli-tree-');
        if ($manifest === false || file_put_contents($manifest, $paths === [] ? '' : implode("\0", array_map(static fn (string $path): string => './' . $path, $paths)) . "\0") === false) {
            if (is_string($manifest)) @unlink($manifest);
            throw new \RuntimeException('Не удалось создать список файлов бэкапа.');
        }
        return $manifest;
    }

    /** @param list<string> $include @param list<string> $exclude @return list<string> */
    public function selectedPaths(string $projectRoot, array $include, array $exclude): array
    {
        $include = array_values(array_filter(array_map($this->normalizePattern(...), $include), static fn (string $pattern): bool => $pattern !== ''));
        $exclude = array_values(array_filter(array_map($this->normalizePattern(...), $exclude), static fn (string $pattern): bool => $pattern !== ''));
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($projectRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $path = substr($item->getPathname(), strlen(rtrim($projectRoot, DIRECTORY_SEPARATOR)) + 1);
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);
            if ($path === '.docker-cli' || str_starts_with($path, '.docker-cli/')) continue;
            if ($include !== [] && !$this->matchesAny($path, $include)) continue;
            if ($this->matchesAny($path, $exclude)) continue;
            $paths[] = $path;
        }
        return $paths;
    }

    public function wipeProject(string $projectRoot): void
    {
        foreach (new \FilesystemIterator($projectRoot) as $item) {
            if ($item->getFilename() === '.docker-cli') continue;
            $this->removePath($item->getPathname());
        }
    }

    private function removePath(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (new \FilesystemIterator($path) as $item) $this->removePath($item->getPathname());
            if (!rmdir($path)) throw new \RuntimeException(sprintf('Не удалось удалить «%s».', $path));
        } elseif (!unlink($path)) {
            throw new \RuntimeException(sprintf('Не удалось удалить «%s».', $path));
        }
    }

    private function normalizePattern(string $pattern): string
    {
        $pattern = str_replace('\\', '/', trim($pattern));
        while (str_starts_with($pattern, './')) $pattern = substr($pattern, 2);
        return trim($pattern, '/');
    }

    /** @param list<string> $patterns */
    private function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $candidate = $path;
            while (true) {
                if ($this->globMatches($pattern, $candidate)) return true;
                $parent = strrpos($candidate, '/');
                if ($parent === false) break;
                $candidate = substr($candidate, 0, $parent);
            }
        }
        return false;
    }

    private function globMatches(string $pattern, string $path): bool
    {
        if (fnmatch($pattern, $path, FNM_PATHNAME)) return true;
        $quoted = preg_quote($pattern, '~');
        $quoted = str_replace(['\\*\\*/', '\\*\\*', '\\*', '\\?'], ['(?:.*/)?', '.*', '[^/]*', '[^/]'], $quoted);
        return preg_match('~^' . $quoted . '$~u', $path) === 1;
    }

    private function compress(string $tar, string $compressor): string
    {
        $extension = self::EXTENSIONS[$compressor];
        $target = $tar . '.' . $extension;
        switch ($compressor) {
            case 'zip':
                $this->run(['zip', '-1', '-j', $target, $tar]);
                if (!unlink($tar)) throw new \RuntimeException('Не удалось удалить исходный tar-архив.');
                break;
            default:
                throw new \LogicException('Потоковый архиватор должен обрабатываться до создания tar-файла.');
        }
        if (!is_file($target)) throw new \RuntimeException('Архиватор не создал ожидаемый файл.');
        return $target;
    }

    /** @param list<string> $command */
    private function run(array $command): void
    {
        if (!$this->executable($command[0])) throw new \RuntimeException(sprintf('Команда «%s» не найдена.', $command[0]));
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);
        if (!is_resource($process)) throw new \RuntimeException(sprintf('Не удалось запустить команду «%s».', $command[0]));
        $code = proc_close($process);
        if ($code !== 0) throw new \RuntimeException(sprintf('Команда «%s» завершилась с кодом %d.', $command[0], $code));
    }

    private function executable(string $command): bool
    {
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
            if ($directory !== '' && is_executable(join_path($directory, $command))) return true;
        }
        return false;
    }

    private function relativePath(string $root, string $path): ?string
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : null;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) return;
        foreach (new \FilesystemIterator($directory) as $item) {
            if ($item->isDir() && !$item->isLink()) $this->removeDirectory($item->getPathname());
            else @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
