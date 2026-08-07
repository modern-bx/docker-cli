<?php

declare(strict_types=1);

namespace DockerCli\Project;

use DockerCli\Config\SystemCompose;
use function DockerCli\Util\join_path;

final class PanelGatewayAuthRenderer
{
    private const PANEL_RELATIVE_PATH = 'config/panel';
    private const HTTP_AUTH_CONFIG_FILE = 'http-auth.conf';
    private const HTTP_AUTH_MAP_FILE = 'playwright-auth-map.conf';
    private const HTTP_AUTH_USER_FILE = '.htpasswd';

    public function render(): void
    {
        $compose = new SystemCompose();
        $panelDirectory = join_path($compose->directory(), self::PANEL_RELATIVE_PATH);
        if (!is_dir($panelDirectory) && !mkdir($panelDirectory, 0755, true) && !is_dir($panelDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create panel config directory "%s".', $panelDirectory));
        }

        $envValues = $this->readEnvValues($compose->envFile());
        $renderer = new HttpBasicAuthRenderer();
        $httpAuthConfig = $renderer->render(
            $envValues,
            $panelDirectory,
            self::HTTP_AUTH_CONFIG_FILE,
            self::HTTP_AUTH_USER_FILE,
            '/etc/nginx/panel/' . self::HTTP_AUTH_USER_FILE
        );

        $mapPath = join_path($panelDirectory, self::HTTP_AUTH_MAP_FILE);
        if ($httpAuthConfig === '') {
            if (is_file($mapPath)) {
                unlink($mapPath);
            }
        } else {
            file_put_contents($mapPath, $renderer->renderPlaywrightBypassMap($envValues));
        }
    }

    /** @return array<string, string> */
    private function readEnvValues(string $envFile): array
    {
        if (!is_file($envFile)) {
            throw new \RuntimeException(sprintf('Env file "%s" not found. Run docker-cli config:init before rendering panel auth config.', $envFile));
        }

        $values = [];
        foreach (file($envFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
        }

        return $values;
    }
}
