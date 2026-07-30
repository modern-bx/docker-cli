<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class ConceptDto implements \JsonSerializable
{
    public function __construct(public string $code, public string $name)
    {
    }

    /** @return array{code: string, name: string} */
    public function jsonSerialize(): array
    {
        return ['code' => $this->code, 'name' => $this->name];
    }
}
