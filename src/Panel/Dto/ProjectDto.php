<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

/**
 * Public project representation used by the panel API.
 *
 * @phpstan-type ProjectPayload array{name: string, language: string|null, framework: string|null, enabled: bool, url: string|null}
 */
final readonly class ProjectDto implements \JsonSerializable
{
    public function __construct(
        public string $name,
        public ?string $language,
        public ?string $framework,
        public bool $enabled,
        public ?string $url,
    ) {
    }

    /** @return array{name: string, language: string|null, framework: string|null, enabled: bool, url: string|null} */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'language' => $this->language,
            'framework' => $this->framework,
            'enabled' => $this->enabled,
            'url' => $this->url,
        ];
    }
}
