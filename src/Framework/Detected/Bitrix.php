<?php

declare(strict_types=1);

namespace DockerCli\Framework\Detected;

class Bitrix extends DetectedFramework
{
    public function getDocumentRoot(): string
    {
        return $this->projectRoot;
    }
}
