<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

/**
 * Public project representation used by the panel API.
 *
 * @phpstan-type ProjectPayload array{name: string, language: string|null, framework: string|null, enabled: bool, protected: bool, url: string|null, tags: list<string>, description: string}
 */
final readonly class ProjectDto implements \JsonSerializable
{
    public function __construct(
        public string $name,
        public ?string $language,
        public ?string $framework,
        public bool $enabled,
        public bool $protected,
        public ?string $url,
        /** @var list<string> */
        public array $tags,
        public string $description,
    ) {
    }

    /** @return array{name: string, language: string|null, framework: string|null, enabled: bool, protected: bool, url: string|null, tags: list<string>, description: string} */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'language' => $this->language,
            'framework' => $this->framework,
            'enabled' => $this->enabled,
            'protected' => $this->protected,
            'url' => $this->url,
            'tags' => $this->tags,
            'description' => $this->description,
        ];
    }
}
