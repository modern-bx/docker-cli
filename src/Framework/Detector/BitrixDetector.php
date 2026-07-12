<?php

declare(strict_types=1);

namespace DockerCli\Framework\Detector;

use DockerCli\Framework\Detected\Bitrix;
use DockerCli\Framework\Detected\DetectedFramework;

final class BitrixDetector implements FrameworkDetectorInterface
{
    public function detect(string $projectRoot): ?DetectedFramework
    {
        $bitrixMarkers = [
            'bitrix/modules/main/include.php',
            'bitrix/header.php',
            'bitrix/footer.php',
        ];

        foreach ($bitrixMarkers as $marker) {
            if (is_file($projectRoot . DIRECTORY_SEPARATOR . $marker)) {
                return new Bitrix($projectRoot);
            }
        }

        return null;
    }
}
