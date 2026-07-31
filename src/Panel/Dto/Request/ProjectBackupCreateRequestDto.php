<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class ProjectBackupCreateRequestDto implements RequestDto
{
    public function __construct(
        public string $name,
        public bool $database,
        public bool $files,
        public bool $mysql,
        public bool $postgres,
    ) {
    }

    public static function fromRequest(RequestData $request): static
    {
        foreach (['database', 'files', 'mysql', 'postgres'] as $field) {
            if (!is_bool($request->body[$field] ?? null)) {
                throw new RequestValidationException('Некорректные параметры создания бэкапа.');
            }
        }

        return new static(
            rawurldecode($request->route['name']),
            $request->body['database'],
            $request->body['files'],
            $request->body['mysql'],
            $request->body['postgres'],
        );
    }
}
