<?php

declare(strict_types=1);

namespace DockerCli\Framework\Description;

final class FrameworkDescription
{
    public function __construct(
        private readonly string $name,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }
}
