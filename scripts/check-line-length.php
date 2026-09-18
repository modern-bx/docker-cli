<?php

declare(strict_types=1);

const MAX_LINE_LENGTH = 120;

$root = dirname(__DIR__);
$paths = [$root . "/bin/docker-cli"];
foreach (["src", "scripts", "tests"] as $directory) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . "/" . $directory, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === "php") {
            $paths[] = $file->getPathname();
        }
    }
}

$violations = [];
foreach ($paths as $path) {
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        fwrite(STDERR, sprintf("Не удалось прочитать файл: %s\n", $path));
        exit(1);
    }

    foreach ($lines as $lineNumber => $line) {
        if (mb_strlen($line) > MAX_LINE_LENGTH) {
            $violations[] = sprintf(
                "%s:%d — %d символов",
                substr($path, strlen($root) + 1),
                $lineNumber + 1,
                mb_strlen($line),
            );
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "Обнаружены строки длиннее " . MAX_LINE_LENGTH . " символов:\n");
    fwrite(STDERR, implode("\n", $violations) . "\n");
    exit(1);
}

fwrite(STDOUT, "Длина строк не превышает " . MAX_LINE_LENGTH . " символов.\n");
