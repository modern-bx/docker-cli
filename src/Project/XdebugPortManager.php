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
        $usedPorts = $this->registeredPorts($projectsDirectory);
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
            if ($this->portNumber($currentPort) !== null) {
                continue;
            }

            while (in_array($port, $usedPorts, true)) {
                ++$port;
            }

            if (!isset($data['data']['project']['xdebug']) || !is_array($data['data']['project']['xdebug'])) {
                $data['data']['project']['xdebug'] = [];
            }
            $data['data']['project']['xdebug']['client_port'] = $port;
            file_put_contents($projectFile, Yaml::dump($data, 4, 2));
            $usedPorts[] = $port;
            $changed = true;

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

            $port = $this->portNumber($data['data']['project']['xdebug']['client_port'] ?? null);
            if ($port !== null) {
                $ports[] = $port;
            }
        }

        return $ports;
    }

    private function portNumber(mixed $port): ?int
    {
        if (is_int($port)) {
            return $port;
        }

        return is_string($port) && ctype_digit($port) ? (int) $port : null;
    }
}
