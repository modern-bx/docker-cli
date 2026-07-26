<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

/** A configured Docker Compose service and its current runtime state. */
final readonly class SystemServiceDto implements \JsonSerializable
{
    public function __construct(
        public string $name,
        public string $image,
        public bool $running,
    ) {
    }

    /** @return array{name: string, image: string, running: bool} */
    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'image' => $this->image, 'running' => $this->running];
    }
}
