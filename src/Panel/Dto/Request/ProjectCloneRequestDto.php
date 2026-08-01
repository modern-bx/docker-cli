<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class ProjectCloneRequestDto implements RequestDto
{
    /** @param list<string> $dbms */
    public function __construct(public string $name, public ?string $to, public bool $skipDb, public array $dbms)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $name = $request->route['name'] ?? null;
        $to = $request->body['to'] ?? null;
        $skipDb = $request->body['skipDb'] ?? false;
        $dbms = $request->body['dbms'] ?? [];
        if (!is_string($name) || ($to !== null && !is_string($to)) || !is_bool($skipDb) || !is_array($dbms) || !array_is_list($dbms)) {
            throw new RequestValidationException('Некорректные параметры клонирования проекта.');
        }
        if (array_filter($dbms, static fn (mixed $item): bool => !is_string($item) || !in_array($item, ['mysql', 'postgres'], true)) !== []) {
            throw new RequestValidationException('Поддерживается клонирование только MySQL и PostgreSQL.');
        }
        return new static($name, is_string($to) && trim($to) !== '' ? trim($to) : null, $skipDb, array_values(array_unique($dbms)));
    }
}
