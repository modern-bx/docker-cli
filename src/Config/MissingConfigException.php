<?php

declare(strict_types=1);

namespace DockerCli\Config;

final class MissingConfigException extends \RuntimeException
{
    /** @param list<string> $missingFiles */
    public function __construct(
        private readonly array $missingFiles,
        private readonly string $configDirectory,
    ) {
        parent::__construct('Docker CLI configuration files are missing.');
    }

    /** @return list<string> */
    public function missingFiles(): array
    {
        return $this->missingFiles;
    }

    public function configDirectory(): string
    {
        return $this->configDirectory;
    }
}
