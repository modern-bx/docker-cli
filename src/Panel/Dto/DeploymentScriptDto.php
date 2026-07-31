<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class DeploymentScriptDto implements \JsonSerializable
{
    /** @param array<string, mixed> $parameters */
    public function __construct(public string $code, public string $name, public array $parameters)
    {
    }

    public function jsonSerialize(): array
    {
        return ['code' => $this->code, 'name' => $this->name, 'parameters' => $this->parameters];
    }
}
