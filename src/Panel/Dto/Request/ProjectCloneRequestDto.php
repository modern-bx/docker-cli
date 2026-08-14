<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class ProjectCloneRequestDto implements RequestDto
{
    /** @param list<string> $dbms @param list<string>|null $dedicatedDatabases */
    public function __construct(public string $name, public ?string $to, public ?string $location, public bool $skipDb, public array $dbms, public ?array $dedicatedDatabases, public string $locationMysql, public string $locationPostgres)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $name = $request->route['name'] ?? null;
        $to = $request->body['to'] ?? null;
        $location = $request->body['location'] ?? null;
        $skipDb = $request->body['skipDb'] ?? false;
        $dbms = $request->body['dbms'] ?? [];
        $dedicatedDatabases = $request->body['dedicatedDatabases'] ?? null;
        $locationMysql = $request->body['locationMysql'] ?? 'system';
        $locationPostgres = $request->body['locationPostgres'] ?? 'system';
        if (!is_string($name) || ($to !== null && !is_string($to)) || ($location !== null && !is_string($location)) || !is_bool($skipDb) || !is_array($dbms) || !array_is_list($dbms)
            || ($dedicatedDatabases !== null && (!is_array($dedicatedDatabases) || !array_is_list($dedicatedDatabases))) || !is_string($locationMysql) || !is_string($locationPostgres)) {
            throw new RequestValidationException('Некорректные параметры клонирования проекта.');
        }
        if (array_filter($dbms, static fn (mixed $item): bool => !is_string($item) || !in_array($item, ['mysql', 'postgres'], true)) !== []) {
            throw new RequestValidationException('Поддерживается клонирование только MySQL и PostgreSQL.');
        }
        if ($dedicatedDatabases !== null && (array_filter($dedicatedDatabases, static fn (mixed $item): bool => !is_string($item) || !in_array($item, ['mysql', 'postgres'], true)) !== [] || count(array_unique($dedicatedDatabases)) !== count($dedicatedDatabases))) {
            throw new RequestValidationException('Выделенные инстансы поддерживаются только для MySQL и PostgreSQL.');
        }
        return new static($name, is_string($to) && trim($to) !== '' ? trim($to) : null, is_string($location) && trim($location) !== '' ? trim($location) : null, $skipDb, array_values(array_unique($dbms)), $dedicatedDatabases === null ? null : array_values($dedicatedDatabases), $locationMysql, $locationPostgres);
    }
}
