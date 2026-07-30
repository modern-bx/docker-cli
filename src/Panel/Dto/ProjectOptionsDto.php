<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class ProjectOptionsDto implements \JsonSerializable
{
    /** @param list<array{path: string, code: string, default: bool}> $locations @param list<ConceptDto> $languages @param array<string, list<ConceptDto>> $frameworks */
    public function __construct(public array $locations, public array $languages, public array $frameworks)
    {
    }

    public function jsonSerialize(): array
    {
        return ['locations' => $this->locations, 'languages' => $this->languages, 'frameworks' => $this->frameworks];
    }
}
