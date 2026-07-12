<?php

declare(strict_types=1);

namespace DockerCli\Framework\Detected;

final class Symfony extends DetectedFramework
{
    public function getDocumentRoot(): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . 'public';
    }
}
