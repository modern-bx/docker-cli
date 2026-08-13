<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

/**
 * Public project representation used by the panel API.
 *
 * @phpstan-type ProjectPayload array{name: string, language: ConceptDto|null, languageVersion: string|null, framework: ConceptDto|null, enabled: bool, protected: bool, url: string|null, mysqlHost: string, postgresHost: string, tags: list<string>, description: string, root: string}
 */
final readonly class ProjectDto implements \JsonSerializable
{
    public function __construct(
        public string $name,
        public ?ConceptDto $language,
        public ?string $languageVersion,
        public ?ConceptDto $framework,
        public bool $enabled,
        public bool $protected,
        public ?string $url,
        public string $mysqlHost,
        public string $postgresHost,
        /** @var list<string> */
        public array $tags,
        public string $description,
        public string $root,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'language' => $this->language,
            'languageVersion' => $this->languageVersion,
            'framework' => $this->framework,
            'enabled' => $this->enabled,
            'protected' => $this->protected,
            'url' => $this->url,
            'mysqlHost' => $this->mysqlHost,
            'postgresHost' => $this->postgresHost,
            'tags' => $this->tags,
            'description' => $this->description,
            'root' => $this->root,
        ];
    }
}
