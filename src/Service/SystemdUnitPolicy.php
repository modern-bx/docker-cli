<?php

declare(strict_types=1);

namespace DockerCli\Service;

final class SystemdUnitPolicy
{
    private const DIRECTORY = '/etc/polkit-1/rules.d';

    public function path(string $unit): string
    {
        return self::DIRECTORY . '/50-docker-cli-' . $unit . '.rules';
    }

    public function contents(string $unit): string|false
    {
        $path = $this->path($unit);

        return is_file($path) ? file_get_contents($path) : false;
    }

    public function install(string $unit, ?string $user): void
    {
        if ($user === null) {
            $this->remove($unit);
            return;
        }

        $rule = sprintf(<<<'JAVASCRIPT'
polkit.addRule(function(action, subject) {
    if (action.id == "org.freedesktop.systemd1.manage-units" &&
        action.lookup("unit") == %s &&
        ["start", "stop", "restart"].indexOf(action.lookup("verb")) >= 0 &&
        subject.user == %s) {
        return polkit.Result.YES;
    }
});

JAVASCRIPT, json_encode($unit, JSON_THROW_ON_ERROR), json_encode($user, JSON_THROW_ON_ERROR));

        $path = $this->path($unit);
        if (@file_put_contents($path, $rule, LOCK_EX) === false || !@chmod($path, 0644)) {
            throw new \RuntimeException(sprintf('Не удалось записать правило управления сервисом в %s.', $path));
        }
    }

    public function remove(string $unit): void
    {
        $path = $this->path($unit);
        if (is_file($path) && !@unlink($path)) {
            throw new \RuntimeException(sprintf('Не удалось удалить правило управления сервисом %s.', $path));
        }
    }

    public function restore(string $unit, string|false $contents): void
    {
        if ($contents === false) {
            @unlink($this->path($unit));
            return;
        }

        @file_put_contents($this->path($unit), $contents, LOCK_EX);
        @chmod($this->path($unit), 0644);
    }
}
