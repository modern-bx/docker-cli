<?php

declare(strict_types=1);

namespace DockerCli\Project;

use Symfony\Component\Yaml\Yaml;
use function DockerCli\Util\join_path;

final class XdebugPortManager
{
    private const FIRST_PROJECT_PORT = 9004;

    public function nextPort(string $projectsDirectory): int
    {
        $port = self::FIRST_PROJECT_PORT;
        $usedPorts = $this->registeredPorts($projectsDirectory);

        while (in_array($port, $usedPorts, true)) {
            ++$port;
        }

        return $port;
    }

    public function rebuildProjectPorts(string $projectsDirectory): bool
    {
        if (!is_dir($projectsDirectory)) {
            return false;
        }

        $projectFiles = glob(join_path($projectsDirectory, '*', 'project.yaml')) ?: [];
        sort($projectFiles);

        $changed = false;
        $port = self::FIRST_PROJECT_PORT;
        foreach ($projectFiles as $projectFile) {
            $data = Yaml::parseFile($projectFile);
            if (!is_array($data)) {
                continue;
            }

            if (!isset($data['data']) || !is_array($data['data'])) {
                $data['data'] = [];
            }
            if (!isset($data['data']['project']) || !is_array($data['data']['project'])) {
                continue;
            }

            $currentPort = $data['data']['project']['xdebug']['client_port'] ?? null;
            if ($currentPort !== $port) {
                if (!isset($data['data']['project']['xdebug']) || !is_array($data['data']['project']['xdebug'])) {
                    $data['data']['project']['xdebug'] = [];
                }
                $data['data']['project']['xdebug']['client_port'] = $port;
                file_put_contents($projectFile, Yaml::dump($data, 4, 2));
                $changed = true;
            }

            ++$port;
        }

        return $changed;
    }

    /** @return list<int> */
    private function registeredPorts(string $projectsDirectory): array
    {
        if (!is_dir($projectsDirectory)) {
            return [];
        }

        $ports = [];
        foreach (glob(join_path($projectsDirectory, '*', 'project.yaml')) ?: [] as $projectFile) {
            $data = Yaml::parseFile($projectFile);
            if (!is_array($data)) {
                continue;
            }

            $port = $data['data']['project']['xdebug']['client_port'] ?? null;
            if (is_int($port)) {
                $ports[] = $port;
                continue;
            }

            if (is_string($port) && ctype_digit($port)) {
                $ports[] = (int) $port;
            }
        }

        return $ports;
    }
}
