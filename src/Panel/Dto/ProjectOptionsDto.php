<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class ProjectOptionsDto implements \JsonSerializable
{
    /** @param list<array{path: string, code: string, default: bool}> $locations @param list<ConceptDto> $languages @param list<string> $languageVersions @param array<string, list<ConceptDto>> $frameworks @param list<DeploymentScriptDto> $deploymentScripts */
    public function __construct(public array $locations, public array $languages, public array $languageVersions, public string $defaultLanguageVersion, public array $frameworks, public array $deploymentScripts)
    {
    }

    public function jsonSerialize(): array
    {
        return ['locations' => $this->locations, 'languages' => $this->languages, 'languageVersions' => $this->languageVersions, 'defaultLanguageVersion' => $this->defaultLanguageVersion, 'frameworks' => $this->frameworks, 'deploymentScripts' => $this->deploymentScripts];
    }
}
