<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$buildDir = $root . '/build';
$pharPath = $buildDir . '/docker-cli.phar';
$executablePath = $buildDir . '/docker-cli';

if (!extension_loaded('phar')) {
    fwrite(STDERR, "The phar extension is required.\n");
    exit(1);
}

if ((bool) ini_get('phar.readonly')) {
    fwrite(STDERR, "Set phar.readonly=0 to build the archive, for example: php -d phar.readonly=0 scripts/build-phar.php\n");
    exit(1);
}

if (!is_dir($buildDir) && !mkdir($buildDir, 0755, true) && !is_dir($buildDir)) {
    fwrite(STDERR, "Unable to create build directory.\n");
    exit(1);
}

foreach ([$pharPath, $executablePath] as $path) {
    if (file_exists($path)) {
        unlink($path);
    }
}

$phar = new Phar($pharPath);
$phar->startBuffering();

$files = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file): bool {
            $name = $file->getFilename();

            return !in_array($name, ['.git', 'build'], true) && !($file->isDir() && $name === 'bin' && str_contains($file->getPath(), '/vendor'));
        }
    )
);

foreach ($files as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }

    $path = $file->getPathname();
    $relativePath = substr($path, strlen($root) + 1);
    if ($relativePath === 'LICENSE' || $relativePath === 'bin/docker-cli' || preg_match('/^(?:resources|src|scripts|vendor)\//', $relativePath) || preg_match('/\.(?:json|lock)$/', $relativePath)) {
        $phar->addFile($path, $relativePath);
    }
}

$phar->setStub("#!/usr/bin/env php\n" . $phar->createDefaultStub('bin/docker-cli'));
$phar->stopBuffering();
copy($pharPath, $executablePath);
chmod($pharPath, 0755);
chmod($executablePath, 0755);

echo "Built {$executablePath}\n";
