<?php

declare(strict_types=1);

namespace DockerCli\Project;

use function DockerCli\Util\join_path;

final class HttpBasicAuthRenderer
{
    public const LOGIN_KEY = 'HTTP_AUTH_LOGIN';
    public const PASSWORD_KEY = 'HTTP_AUTH_PASSWORD';

    /** @param array<string, string> $envValues */
    public function render(array $envValues, string $directory, ?string $configFile, string $userFile, string $userFileContainerPath): string
    {
        $login = $envValues[self::LOGIN_KEY] ?? '';
        $password = $envValues[self::PASSWORD_KEY] ?? '';
        $configPath = $configFile === null ? null : join_path($directory, $configFile);
        $userPath = join_path($directory, $userFile);

        if ($login === '' || $password === '') {
            if ($configPath !== null) {
                $this->removeIfExists($configPath);
            }
            $this->removeIfExists($userPath);

            return '';
        }

        if (str_contains($login, ':') || str_contains($login, "\n") || str_contains($login, "\r")) {
            throw new \RuntimeException(self::LOGIN_KEY . ' must not contain colon or line breaks.');
        }

        file_put_contents($userPath, $login . ':{SHA}' . base64_encode(sha1($password, true)) . PHP_EOL);

        $config = sprintf(
            "auth_basic \"Restricted\";\nauth_basic_user_file %s;\n",
            $userFileContainerPath
        );
        if ($configPath !== null) {
            file_put_contents($configPath, $config);
        }

        return "\n    " . str_replace("\n", "\n    ", rtrim($config)) . "\n";
    }

    private function removeIfExists(string $file): void
    {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
