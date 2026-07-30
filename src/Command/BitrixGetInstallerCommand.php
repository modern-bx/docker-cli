<?php

declare(strict_types=1);

namespace DockerCli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class BitrixGetInstallerCommand extends AbstractCommand
{
    private const BASE_URL = 'https://www.1c-bitrix.ru/download/';
    private const CACHE_RELATIVE_PATH = '.config/docker-cli/cache/bitrix-get-installer/distro';
    private const DEFAULT_EDITIONS = [
        'bitrix' => 'start',
        'bitrix24' => 'business',
    ];

    /** @var array<string, array<string, string>> */
    private const EDITIONS = [
        'bitrix' => [
            'business' => 'business_encode_php5.tar.gz',
            'expert' => 'expert_encode_php5.tar.gz',
            'small_business' => 'small_business_encode_php5.tar.gz',
            'standard' => 'standard_encode_php5.tar.gz',
            'start' => 'start_encode_php5.tar.gz',
        ],
        'bitrix24' => [
            'business' => 'portal/bitrix24_encode_php5.tar.gz',
            'enterprise' => 'portal/bitrix24_enterprise_encode_php5.tar.gz',
            'enterprise_postgresql' => 'portal/bitrix24_enterprise_postgresql_encode.zip',
        ],
    ];

    public function __construct()
    {
        parent::__construct('bitrix:get-installer');
        $this->setDescription('Скачать дистрибутив 1С-Битрикс или 1С-Битрикс24.');
        $this->addOption('product', null, InputOption::VALUE_REQUIRED, 'Продукт: bitrix или bitrix24.', 'bitrix');
        $this->addOption('edition', null, InputOption::VALUE_REQUIRED, 'Редакция продукта (по умолчанию: start для bitrix, business для bitrix24).');
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Файл или директория для сохранения архива.', '.');
        $this->addOption('extract', null, InputOption::VALUE_NONE, 'Распаковать архив рядом с ним и удалить архив.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $product = $this->stringOption($input, 'product');
        $edition = $this->stringOption($input, 'edition');
        $path = $this->stringOption($input, 'path');

        if (!isset(self::EDITIONS[$product])) {
            $this->writeMessage($output, sprintf('<error>Неизвестный продукт "%s". Доступно: %s.</error>', $product, implode(', ', array_keys(self::EDITIONS))));
            return Command::INVALID;
        }

        if ($edition === '') {
            $edition = self::DEFAULT_EDITIONS[$product];
        }

        if (!isset(self::EDITIONS[$product][$edition])) {
            $this->writeMessage($output, sprintf('<error>Неизвестная редакция "%s" для продукта "%s". Доступно: %s.</error>', $edition, $product, implode(', ', array_keys(self::EDITIONS[$product]))));
            return Command::INVALID;
        }

        $remotePath = self::EDITIONS[$product][$edition];
        $url = self::BASE_URL . $remotePath;
        $target = $this->resolveTargetPath($path, basename($remotePath));

        if (file_exists($target)) {
            $this->writeMessage($output, sprintf('<error>Файл уже существует: %s</error>', $target));
            return Command::FAILURE;
        }

        $directory = dirname($target);
        if (!is_dir($directory)) {
            $this->writeMessage($output, sprintf('<error>Директория не найдена: %s</error>', $directory));
            return Command::FAILURE;
        }

        $cache = $this->cachePath($remotePath);
        $contentLength = $this->remoteContentLength($url);
        if ($contentLength !== null && is_file($cache) && filesize($cache) === $contentLength) {
            $this->writeMessage($output, sprintf('<info>Использую кешированный дистрибутив: %s</info>', $cache));
        } else {
            $this->writeMessage($output, sprintf('<info>Скачиваю %s/%s: %s</info>', $product, $edition, $url));
            $this->download($url, $cache);
            $this->writeMessage($output, sprintf('<info>Кеш обновлен: %s</info>', $cache));
        }

        $this->copyFromCache($cache, $target);
        $this->writeMessage($output, sprintf('<info>Архив сохранен: %s</info>', $target));

        if ((bool) $input->getOption('extract')) {
            $this->extract($target, $directory);
            unlink($target);
            $this->writeMessage($output, sprintf('<info>Архив распакован в %s и удален.</info>', $directory));
        }

        return Command::SUCCESS;
    }

    private function stringOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);
        return is_string($value) && $value !== '' ? $value : '';
    }

    private function resolveTargetPath(string $path, string $remoteFilename): string
    {
        if ($path === '' || is_dir($path) || str_ends_with($path, DIRECTORY_SEPARATOR)) {
            return rtrim($path === '' ? '.' : $path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $remoteFilename;
        }

        return $path;
    }

    private function cachePath(string $remotePath): string
    {
        $home = getenv('HOME') ?: throw new \RuntimeException('HOME environment variable is not set.');

        return $home . DIRECTORY_SEPARATOR . self::CACHE_RELATIVE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $remotePath);
    }

    private function remoteContentLength(string $url): ?int
    {
        $read = @fopen($url, 'rb', false, stream_context_create(['http' => [
            'method' => 'HEAD',
            'ignore_errors' => true,
            'user_agent' => 'BitrixSiteLoader',
        ]]));
        if (!is_resource($read)) {
            return null;
        }

        fclose($read);
        $this->assertSuccessfulResponse($http_response_header ?? [], $url);

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^Content-Length:\s*(\d+)\s*$/i', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function download(string $url, string $target): void
    {
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Не удалось создать директорию кеша: %s', $directory));
        }

        $temporary = tempnam($directory, '.download-');
        if ($temporary === false) {
            throw new \RuntimeException(sprintf('Не удалось создать временный файл в директории: %s', $directory));
        }

        $read = @fopen($url, 'rb', false, stream_context_create(['http' => ['user_agent' => 'BitrixSiteLoader', 'ignore_errors' => true]]));
        if (!is_resource($read)) {
            @unlink($temporary);
            throw new \RuntimeException(sprintf('Не удалось открыть URL: %s', $url));
        }

        $write = @fopen($temporary, 'wb');
        if (!is_resource($write)) {
            fclose($read);
            @unlink($temporary);
            throw new \RuntimeException(sprintf('Не удалось создать файл: %s', $temporary));
        }

        try {
            if (stream_copy_to_stream($read, $write) === false) {
                throw new \RuntimeException(sprintf('Не удалось скачать файл: %s', $url));
            }
        } finally {
            fclose($read);
            fclose($write);
        }

        try {
            $this->assertSuccessfulResponse($http_response_header ?? [], $url);
        } catch (\Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        }

        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new \RuntimeException(sprintf('Не удалось обновить кеш: %s', $target));
        }
    }

    private function copyFromCache(string $cache, string $target): void
    {
        if (!is_file($cache)) {
            throw new \RuntimeException(sprintf('Кешированный файл не найден: %s', $cache));
        }

        if (!copy($cache, $target)) {
            throw new \RuntimeException(sprintf('Не удалось скопировать файл из кеша: %s -> %s', $cache, $target));
        }
    }

    /** @param list<string> $headers */
    private function assertSuccessfulResponse(array $headers, string $url): void
    {
        $statusLine = $headers[0] ?? '';
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $statusLine, $matches) === 1 && (int) $matches[1] >= 400) {
            throw new \RuntimeException(sprintf('Сервер вернул ошибку %s для %s', $matches[1], $url));
        }
    }

    /** @param list<string> $command */
    private function runProcess(array $command): void
    {
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException(sprintf('Не удалось запустить команду: %s', implode(' ', $command)));
        }

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf('Команда завершилась с кодом %d: %s', $exitCode, implode(' ', $command)));
        }
    }

    private function extract(string $archive, string $directory): void
    {
        if (str_ends_with($archive, '.zip')) {
            $zip = new \ZipArchive();
            if ($zip->open($archive) !== true) {
                throw new \RuntimeException(sprintf('Не удалось открыть zip-архив: %s', $archive));
            }
            $zip->extractTo($directory);
            $zip->close();
            return;
        }

        if (str_ends_with($archive, '.tar.gz')) {
            $this->runProcess(['tar', '-xzf', $archive, '-C', $directory]);
            return;
        }

        throw new \RuntimeException(sprintf('Неизвестный формат архива: %s', $archive));
    }
}
