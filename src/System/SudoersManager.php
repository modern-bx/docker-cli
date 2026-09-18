<?php

declare(strict_types=1);

namespace DockerCli\System;

final class SudoersManager {
    private const COMMANDS = ["/usr/bin/cp", "/usr/bin/chown", "/usr/bin/chmod"];

    public function __construct(private readonly string $directory = "/etc/sudoers.d") {}

    public function setup(string $user, bool $update): string {
        $file = $this->file($user);
        if (file_exists($file) && !$update) {
            throw new \RuntimeException(
                sprintf(
                    'Конфигурация sudo для пользователя "%s" уже ' .
                        "существует. Используйте --update для её " .
                        "обновления.",
                    $user,
                ),
            );
        }
        if (!is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Директория sudoers "%s" не существует.', $this->directory));
        }

        $temporary = tempnam($this->directory, ".docker-cli-");
        if ($temporary === false) {
            throw new \RuntimeException(
                "Не удалось создать временный файл sudoers. " . "Запустите команду через sudo.",
            );
        }

        try {
            $content = sprintf(
                "# Managed by docker-cli system:setup\n%s ALL=(root) NOPASSWD: %s\n",
                $user,
                implode(", ", self::COMMANDS),
            );
            if (file_put_contents($temporary, $content, LOCK_EX) === false || !chmod($temporary, 0440)) {
                throw new \RuntimeException("Не удалось записать конфигурацию sudoers.");
            }
            $this->validate($temporary);
            if (!rename($temporary, $file)) {
                throw new \RuntimeException("Не удалось установить конфигурацию sudoers.");
            }
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }

        return $file;
    }

    public function rollback(string $user): string {
        $file = $this->file($user);
        if (file_exists($file) && !unlink($file)) {
            throw new \RuntimeException("Не удалось удалить конфигурацию sudoers. " . "Запустите команду через sudo.");
        }

        return $file;
    }

    public function file(string $user): string {
        return rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "docker-cli-" . $user;
    }

    private function validate(string $file): void {
        $visudo = is_executable("/usr/sbin/visudo")
            ? "/usr/sbin/visudo"
            : (is_executable("/sbin/visudo")
                ? "/sbin/visudo"
                : null);
        if ($visudo === null) {
            throw new \RuntimeException("Команда visudo не найдена; конфигурация sudoers " . "не была установлена.");
        }
        $process = proc_open(
            [$visudo, "-c", "-f", $file],
            [["file", "/dev/null", "r"], ["file", "/dev/null", "w"], STDERR],
            $pipes,
        );
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new \RuntimeException("Проверка конфигурации sudoers завершилась с ошибкой.");
        }
    }
}
