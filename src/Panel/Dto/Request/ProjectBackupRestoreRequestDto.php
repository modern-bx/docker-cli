<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class ProjectBackupRestoreRequestDto implements RequestDto
{
    public function __construct(public string $name, public string $backup, public string $database, public string $location = '')
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $database = $request->body['database'] ?? 'mysql';
        $location = $request->body['location'] ?? '';
        if (!in_array($database, ['mysql', 'postgres'], true) || !is_string($location)
            || ($location !== '' && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $location) !== 1)) {
            throw new RequestValidationException('Некорректный тип СУБД бэкапа.');
        }
        return new static(rawurldecode($request->route['name']), rawurldecode($request->route['backup']), $database, $location);
    }
}
