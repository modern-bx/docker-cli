<?php

declare(strict_types=1);

namespace DockerCli\Framework\Description;

final class FrameworkDescription
{
    public function __construct(
        private readonly FrameworkName $name,
        private readonly FrameworkCodeName $codeName,
    ) {
    }

    public function getName(): FrameworkName
    {
        return $this->name;
    }

    public function getCodeName(): FrameworkCodeName
    {
        return $this->codeName;
    }
}
