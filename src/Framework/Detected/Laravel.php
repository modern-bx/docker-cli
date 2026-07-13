<?php

declare(strict_types=1);

namespace DockerCli\Framework\Detected;

use function DockerCli\Util\join_path;

final class Laravel extends DetectedFramework
{
    public function getDocumentRoot(): string
    {
        return join_path($this->projectRoot, 'public');
    }
}
