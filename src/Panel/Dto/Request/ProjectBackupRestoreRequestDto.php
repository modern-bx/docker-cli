<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class ProjectBackupRestoreRequestDto implements RequestDto
{
    public function __construct(public string $name, public string $backup, public string $database)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $database = $request->body['database'] ?? 'mysql';
        if (!in_array($database, ['mysql', 'postgres'], true)) {
            throw new RequestValidationException('Некорректный тип СУБД бэкапа.');
        }
        return new static(rawurldecode($request->route['name']), rawurldecode($request->route['backup']), $database);
    }
}
