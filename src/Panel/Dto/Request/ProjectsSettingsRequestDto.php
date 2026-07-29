<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class ProjectsSettingsRequestDto implements RequestDto
{
    /** @param list<array{path: string, default: bool}> $locations */
    public function __construct(public array $locations)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $locations = $request->body['locations'] ?? null;
        if (!is_array($locations) || $locations === [] || !array_is_list($locations)) {
            throw new RequestValidationException('Добавьте хотя бы одно расположение проектов.');
        }

        $validated = [];
        $defaults = 0;
        foreach ($locations as $location) {
            if (!is_array($location) || array_keys($location) !== ['path', 'default']
                || !is_string($location['path']) || trim($location['path']) === ''
                || strlen($location['path']) > 4096 || !is_bool($location['default'])) {
                throw new RequestValidationException('Некорректное расположение проектов.');
            }
            $path = trim($location['path']);
            if (in_array($path, array_column($validated, 'path'), true)) {
                throw new RequestValidationException(sprintf('Путь «%s» указан несколько раз.', $path));
            }
            $defaults += $location['default'] ? 1 : 0;
            $validated[] = ['path' => $path, 'default' => $location['default']];
        }
        if ($defaults !== 1) {
            throw new RequestValidationException('Выберите одно расположение по умолчанию.');
        }
        return new static($validated);
    }
}
