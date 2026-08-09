<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\SystemCompose;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SystemSelfUpdateCommand extends AbstractCommand
{
    private const SYSTEMD_UNIT_PATTERN = '/^docker-cli\.(?:panel|queue\.[a-zA-Z0-9_.@-]+)\.service$/D';

    public function __construct(private readonly ?SystemCompose $compose = null)
    {
        parent::__construct('system:self-update');
        $this->setDescription('Обновить docker-cli до последней сборки основной ветки.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $compose = $this->compose ?? new SystemCompose();
        $binary = \Phar::running(false);
        if ($binary === '') {
            $this->writeMessage($output, '<error>Самообновление доступно только при запуске docker-cli из PHAR-архива.</error>');
            return Command::FAILURE;
        }

        $lock = @fopen($binary . '.update.lock', 'c');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            $this->writeMessage($output, '<error>Другое обновление docker-cli уже выполняется.</error>');
            return Command::FAILURE;
        }

        $temporary = null;
        try {
            $url = $this->releaseUrl($compose);
            $this->writeMessage($output, sprintf('<info>Скачиваем обновление: %s</info>', $url));
            $temporary = $this->download($url, dirname($binary));
            $this->validatePhar($temporary);
            $runningUnits = $this->runningSystemdUnits();
            $this->replaceBinary($binary, $temporary);
            $temporary = null;
            $this->writeMessage($output, sprintf('<info>Бинарник %s обновлён.</info>', $binary));

            $status = $this->runCommand([$binary, 'system:stop'], $output);
            if ($status === Command::SUCCESS) {
                $status = $this->runCommand([$binary, 'config:init', '--update', '--migrate', '--rebuild', '--force'], $output);
                if ($status !== Command::SUCCESS) {
                    $this->writeMessage($output, '<comment>Обновление конфигурации завершилось с ошибкой; пробуем снова запустить систему.</comment>');
                    $this->runCommand([$binary, 'system:start'], $output);
                } else {
                    $status = $this->runCommand([$binary, 'system:start'], $output);
                }
            }

            foreach ($runningUnits as $unit) {
                $this->writeMessage($output, sprintf('<info>Перезапускаем фоновый сервис %s.</info>', $unit));
                $restartStatus = $this->runCommand(['systemctl', 'restart', $unit], $output);
                if ($status === Command::SUCCESS && $restartStatus !== Command::SUCCESS) $status = $restartStatus;
            }

            if ($status !== Command::SUCCESS) return $status;
            $this->writeMessage($output, '<info>docker-cli успешно обновлён.</info>');
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $this->writeMessage($output, '<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        } finally {
            if (is_string($temporary)) @unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($binary . '.update.lock');
        }
    }

    private function releaseUrl(SystemCompose $compose): string
    {
        $namespace = trim($compose->envValue('SOURCE_IMAGE_NAMESPACE'), '/');
        $name = trim($compose->envValue('SOURCE_IMAGE_NAME', 'docker-cli'), '/');
        $branch = trim($compose->envValue('SOURCE_IMAGE_MAIN_BRANCH', 'main'));
        foreach (['SOURCE_IMAGE_NAMESPACE' => $namespace, 'SOURCE_IMAGE_NAME' => $name, 'SOURCE_IMAGE_MAIN_BRANCH' => $branch] as $key => $value) {
            if ($value === '' || preg_match('/^[a-zA-Z0-9_.-]+$/D', $value) !== 1) {
                throw new \RuntimeException(sprintf('Некорректное или пустое значение %s.', $key));
            }
        }

        return sprintf('https://github.com/%s/%s/releases/download/%s-latest/%s.phar',
            rawurlencode($namespace), rawurlencode($name), rawurlencode($branch), rawurlencode($name));
    }

    private function download(string $url, string $directory): string
    {
        $temporary = $directory . '/.docker-cli-update-' . bin2hex(random_bytes(8)) . '.phar';
        $context = stream_context_create(['http' => [
            'follow_location' => 1,
            'max_redirects' => 10,
            'timeout' => 60,
            'user_agent' => 'docker-cli self-update',
        ]]);
        $source = @fopen($url, 'rb', false, $context);
        if (!is_resource($source)) {
            throw new \RuntimeException(sprintf('Не удалось скачать обновление с %s.', $url));
        }
        $target = @fopen($temporary, 'xb');
        if (!is_resource($target)) {
            fclose($source);
            throw new \RuntimeException(sprintf('Не удалось создать временный файл в %s.', $directory));
        }

        try {
            $bytes = stream_copy_to_stream($source, $target);
        } finally {
            fclose($source);
            fclose($target);
        }
        if ($bytes === false || $bytes === 0) {
            @unlink($temporary);
            throw new \RuntimeException('Скачан пустой файл обновления.');
        }

        return $temporary;
    }

    private function validatePhar(string $file): void
    {
        try {
            $phar = new \Phar($file);
            if (!isset($phar['bin/docker-cli']) || $phar->getSignature() === false) {
                throw new \RuntimeException('Архив не содержит исполняемый файл или подпись.');
            }
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Скачанный файл не является корректным PHAR-архивом: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function replaceBinary(string $binary, string $temporary): void
    {
        $permissions = fileperms($binary);
        if ($permissions === false || !@chmod($temporary, $permissions & 0777) || !@rename($temporary, $binary)) {
            throw new \RuntimeException(sprintf('Не удалось заменить исполняемый файл %s.', $binary));
        }
        clearstatcache(true, $binary);
    }

    /** @return list<string> */
    private function runningSystemdUnits(): array
    {
        [$status, $output] = $this->captureCommand([
            'systemctl', 'list-units', '--type=service', '--state=running', '--no-legend', '--plain',
            'docker-cli.panel.service', 'docker-cli.queue.*.service',
        ]);
        if ($status !== Command::SUCCESS) {
            return [];
        }

        $units = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $unit = preg_split('/\s+/', trim($line), 2)[0] ?? '';
            if (preg_match(self::SYSTEMD_UNIT_PATTERN, $unit) === 1) $units[] = $unit;
        }

        return array_values(array_unique($units));
    }

    /** @param list<string> $command */
    private function runCommand(array $command, OutputInterface $output): int
    {
        $this->writeMessage($output, '<comment>' . implode(' ', array_map('escapeshellarg', $command)) . '</comment>', MessageLevel::Debug);
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException(sprintf('Не удалось запустить команду %s.', $command[0]));
        }

        return proc_close($process);
    }

    /** @param list<string> $command @return array{int, string} */
    private function captureCommand(array $command): array
    {
        $process = proc_open($command, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) return [Command::FAILURE, ''];
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), trim($stdout !== '' ? $stdout : $stderr)];
    }
}
