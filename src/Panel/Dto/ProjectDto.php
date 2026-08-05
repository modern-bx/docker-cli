<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

/**
 * Public project representation used by the panel API.
 *
 * @phpstan-type ProjectPayload array{name: string, language: ConceptDto|null, languageVersion: string|null, framework: ConceptDto|null, enabled: bool, protected: bool, url: string|null, tags: list<string>, description: string, root: string}
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
        /** @var list<string> */
        public array $tags,
        public string $description,
        public string $root,
    ) {
    }

    /** @return array{name: string, language: ConceptDto|null, languageVersion: string|null, framework: ConceptDto|null, enabled: bool, protected: bool, url: string|null, tags: list<string>, description: string, root: string} */
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
            'tags' => $this->tags,
            'description' => $this->description,
            'root' => $this->root,
        ];
    }
}
