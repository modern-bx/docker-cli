<?php

declare(strict_types=1);

namespace DockerCli\Command;

use Symfony\Component\Console\Input\InputInterface;

abstract class SystemUserCommand extends AbstractCommand
{
    protected function resolveUser(InputInterface $input): string
    {
        $option = $input->getOption("user");
        $user = is_string($option) && trim($option) !== "" ? trim($option) : $this->invokingUser();
        if (preg_match('/^[a-z_][a-z0-9_.-]*\$?$/i', $user) !== 1) {
            throw new \RuntimeException("Некорректный логин пользователя.");
        }

        exec("id -u -- " . escapeshellarg($user) . " 2>/dev/null", $output, $status);
        if ($status !== 0) {
            throw new \RuntimeException(sprintf('Пользователь "%s" не найден.', $user));
        }

        return $user;
    }

    private function invokingUser(): string
    {
        $sudoUser = getenv("SUDO_USER");
        if (is_string($sudoUser) && $sudoUser !== "" && $sudoUser !== "root") {
            return $sudoUser;
        }
        if (function_exists("posix_geteuid") && function_exists("posix_getpwuid")) {
            $account = posix_getpwuid(posix_geteuid());
            if (is_array($account) && is_string($account["name"] ?? null)) {
                return $account["name"];
            }
        }
        $user = trim((string) shell_exec("id -un 2>/dev/null"));
        if ($user === "") {
            throw new \RuntimeException("Не удалось определить текущего " . "пользователя. Укажите --user.");
        }

        return $user;
    }
}
