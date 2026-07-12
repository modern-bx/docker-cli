<?php

declare(strict_types=1);

namespace DockerCli\Framework\Detector;

use DockerCli\Framework\Detected\Bitrix24;
use DockerCli\Framework\Detected\DetectedFramework;

final class Bitrix24Detector implements FrameworkDetectorInterface
{
    public function detect(string $projectRoot): ?DetectedFramework
    {
        if (!is_dir($projectRoot . DIRECTORY_SEPARATOR . 'bitrix')) {
            return null;
        }

        $bitrix24Markers = [
            'bitrix/modules/intranet',
            'bitrix/modules/tasks',
            'bitrix/modules/crm',
        ];

        foreach ($bitrix24Markers as $marker) {
            if (is_dir($projectRoot . DIRECTORY_SEPARATOR . $marker)) {
                return new Bitrix24($projectRoot);
            }
        }

        return null;
    }
}
