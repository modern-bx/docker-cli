<?php

declare(strict_types=1);

namespace DockerCli\Project;

use function DockerCli\Util\join_path;

final class HttpBasicAuthRenderer
{
    public const LOGIN_KEY = 'HTTP_AUTH_LOGIN';
    public const PASSWORD_KEY = 'HTTP_AUTH_PASSWORD';
    public const PLAYWRIGHT_IP_KEY = 'PLAYWRIGHT_IP';
    public const DEFAULT_PLAYWRIGHT_IP = '172.30.0.254';

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
            "auth_basic \$docker_cli_http_auth_realm;\nauth_basic_user_file %s;\n",
            $userFileContainerPath
        );
        if ($configPath !== null) {
            file_put_contents($configPath, $config);
        }

        return "\n    " . str_replace("\n", "\n    ", rtrim($config)) . "\n";
    }

    /** @param array<string, string> $envValues */
    public function renderPlaywrightBypassMap(array $envValues): string
    {
        $playwrightIp = $envValues[self::PLAYWRIGHT_IP_KEY] ?? self::DEFAULT_PLAYWRIGHT_IP;
        if (filter_var($playwrightIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \RuntimeException(self::PLAYWRIGHT_IP_KEY . ' must be a valid IPv4 address.');
        }

        return sprintf(
            "geo \$http_x_forwarded_for \$docker_cli_http_auth_realm {\n    default \"Restricted\";\n    %s off;\n}\n",
            $playwrightIp
        );
    }

    private function removeIfExists(string $file): void
    {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
