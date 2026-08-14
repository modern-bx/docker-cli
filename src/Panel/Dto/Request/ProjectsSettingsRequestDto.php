<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class ProjectsSettingsRequestDto implements RequestDto
{
    /** @param list<array{path: string, code: string, default: bool}> $locations */
    /** @param list<array{path: string, code: string, default: bool}> $databaseLocations */
    public function __construct(public array $locations, public array $databaseLocations)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $locations = $request->body['locations'] ?? null;
        $databaseLocations = $request->body['databaseLocations'] ?? null;

        return new static(
            self::validateLocations($locations, 'файлов проектов'),
            self::validateLocations($databaseLocations, 'баз данных'),
        );
    }

    /** @return list<array{path: string, code: string, default: bool}> */
    private static function validateLocations(mixed $locations, string $type): array
    {
        if (!is_array($locations) || $locations === [] || !array_is_list($locations)) {
            throw new RequestValidationException(sprintf('Добавьте хотя бы одно расположение %s.', $type));
        }

        $validated = [];
        $defaults = 0;
        foreach ($locations as $location) {
            if (!is_array($location) || array_keys($location) !== ['path', 'code', 'default']
                || !is_string($location['path']) || trim($location['path']) === ''
                || strlen($location['path']) > 4096 || !is_string($location['code']) || !is_bool($location['default'])) {
                throw new RequestValidationException(sprintf('Некорректное расположение %s.', $type));
            }
            $path = trim($location['path']);
            if (in_array($path, array_column($validated, 'path'), true)) {
                throw new RequestValidationException(sprintf('Путь «%s» указан несколько раз.', $path));
            }
            $defaults += $location['default'] ? 1 : 0;
            $code = trim($location['code']);
            if ($code !== '' && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $code) !== 1) {
                throw new RequestValidationException('Некорректное кодовое имя расположения.');
            }
            if ($code !== '' && in_array($code, array_column($validated, 'code'), true)) {
                throw new RequestValidationException(sprintf('Код «%s» указан несколько раз.', $code));
            }
            $validated[] = ['path' => $path, 'code' => $code, 'default' => $location['default']];
        }
        if ($defaults !== 1) {
            throw new RequestValidationException('Выберите одно расположение по умолчанию.');
        }
        return $validated;
    }
}
