<?php

declare(strict_types=1);

namespace DockerCli\Service;

use DockerCli\Config\SystemCompose;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TranslatorFactory
{
    public static function create(?SystemCompose $compose = null): TranslatorInterface
    {
        $locale = self::readLocale($compose ?? new SystemCompose()) ?? 'ru';
        $translator = new Translator($locale);
        $translator->setFallbackLocales(['ru']);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', dirname(__DIR__, 2) . '/resources/translations/messages.ru.yaml', 'ru');

        return $translator;
    }

    private static function readLocale(SystemCompose $compose): ?string
    {
        $envFile = $compose->envFile();
        if (!is_file($envFile)) {
            return null;
        }

        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            if (trim($key) === 'APP_LOCALE') {
                return trim($value, " \t\n\r\0\x0B\"'");
            }
        }

        return null;
    }
}
