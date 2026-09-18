<?php

declare(strict_types=1);

namespace DockerCli\Project;

use DockerCli\Config\SystemCompose;

final class DedicatedDatabaseDirectoryRemover
{
    /** @var \Closure(list<string>): int */
    private readonly \Closure $processRunner;

    /** @param (\Closure(list<string>): int)|null $processRunner */
    public function __construct(?\Closure $processRunner = null, private readonly ?SystemCompose $compose = null)
    {
        $this->processRunner = $processRunner ?? static function (array $command): int {
            $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);

            return is_resource($process) ? proc_close($process) : 1;
        };
    }

    /**
     * @param list<string> $drivers
     * @param array<string, mixed> $projectConfig
     */
    public function remove(string $projectName, array $drivers, array $projectConfig): bool
    {
        $compose = $this->compose ?? new SystemCompose();
        foreach ($drivers as $driver) {
            $databaseConfig = $projectConfig["data"]["databases"][$driver] ?? null;
            if (
                !is_array($databaseConfig) ||
                ($databaseConfig["hostname"] ?? null) !== sprintf("docker-cli-%s-%s", $driver, $projectName)
            ) {
                continue;
            }
            $location = $databaseConfig["location"] ?? null;
            $directory = $compose->dedicatedDatabaseDirectory(
                $projectName,
                $driver,
                is_string($location) ? $location : null,
            );
            if (($this->processRunner)(["sudo", "rm", "-rf", "--", $directory]) !== 0) {
                return false;
            }
        }

        return true;
    }
}
