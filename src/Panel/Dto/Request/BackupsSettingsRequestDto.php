<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class BackupsSettingsRequestDto implements RequestDto
{
    /**
     * @param list<array{path: string, code: string, default: bool}> $locations
     * @param list<array{name: string, code: string, include: list<string>, exclude: list<string>}> $fileStrategies
     */
    public function __construct(public array $locations, public array $fileStrategies)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $locations = $request->body['locations'] ?? null;
        if (!is_array($locations) || $locations === [] || !array_is_list($locations)) {
            throw new RequestValidationException('Добавьте хотя бы одно расположение бэкапов.');
        }

        $validated = [];
        $defaults = 0;
        foreach ($locations as $location) {
            if (!is_array($location) || array_keys($location) !== ['path', 'code', 'default']
                || !is_string($location['path']) || trim($location['path']) === ''
                || strlen($location['path']) > 4096 || !is_string($location['code']) || !is_bool($location['default'])) {
                throw new RequestValidationException('Некорректное расположение бэкапов.');
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
        $validatedStrategies = self::validateStrategies($request->body['fileStrategies'] ?? null);
        return new static($validated, $validatedStrategies);
    }

    /** @return list<array{name: string, code: string, include: list<string>, exclude: list<string>}> */
    private static function validateStrategies(mixed $strategies): array
    {
        if (!is_array($strategies) || !array_is_list($strategies)) {
            throw new RequestValidationException('Некорректные стратегии.');
        }
        $validatedStrategies = [];
        foreach ($strategies as $strategy) {
            if (!is_array($strategy) || array_keys($strategy) !== ['name', 'code', 'include', 'exclude', 'databaseInclude', 'databaseExclude']
                || !is_string($strategy['name']) || trim($strategy['name']) === '' || strlen($strategy['name']) > 255
                || !is_string($strategy['code']) || !is_array($strategy['include']) || !array_is_list($strategy['include'])
                || !is_array($strategy['exclude']) || !array_is_list($strategy['exclude'])
                || !is_array($strategy['databaseInclude']) || !array_is_list($strategy['databaseInclude'])
                || !is_array($strategy['databaseExclude']) || !array_is_list($strategy['databaseExclude'])) {
                throw new RequestValidationException('Некорректная стратегия.');
            }
            $code = trim($strategy['code']);
            if ($code !== '' && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $code) !== 1) {
                throw new RequestValidationException('Некорректное кодовое имя стратегии.');
            }
            if ($code !== '' && in_array($code, array_column($validatedStrategies, 'code'), true)) {
                throw new RequestValidationException(sprintf('Код стратегии «%s» указан несколько раз.', $code));
            }
            $patterns = [];
            foreach (['include', 'exclude', 'databaseInclude', 'databaseExclude'] as $key) {
                if (array_filter($strategy[$key], static fn ($value): bool => !is_string($value) || trim($value) === '' || strlen($value) > 4096)) {
                    throw new RequestValidationException('Некорректный путь, имя или glob-шаблон стратегии.');
                }
                $patterns[$key] = array_map(trim(...), $strategy[$key]);
            }
            $validatedStrategies[] = ['name' => trim($strategy['name']), 'code' => $code, ...$patterns];
        }
        return $validatedStrategies;
    }
}
