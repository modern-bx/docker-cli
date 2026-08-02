<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class ProjectBackupRestoreRequestDto implements RequestDto
{
    /** @param list<string> $databases */
    public function __construct(public string $name, public string $backup, public string $database, public string $location = '', public bool $files = false, public bool $force = true, public bool $wipe = false, public array $databases = [])
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $database = $request->body['database'] ?? 'mysql';
        $location = $request->body['location'] ?? '';
        $files = $request->body['files'] ?? false;
        $force = $request->body['force'] ?? true;
        $wipe = $request->body['wipe'] ?? false;
        $databases = $request->body['databases'] ?? ($database === '' ? [] : [$database]);
        if (!in_array($database, ['', 'mysql', 'postgres'], true) || !is_string($location) || !is_bool($files) || !is_bool($force) || !is_bool($wipe)
            || !is_array($databases) || !array_is_list($databases) || array_diff($databases, ['mysql', 'postgres']) !== [] || count(array_unique($databases)) !== count($databases)
            || ($databases === [] && !$files)
            || ($location !== '' && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $location) !== 1)) {
            throw new RequestValidationException('Некорректные параметры восстановления бэкапа.');
        }
        return new static(rawurldecode($request->route['name']), rawurldecode($request->route['backup']), $database, $location, $files, $force, $wipe, $databases);
    }
}
