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
        public string $location,
        public string $strategy = '',
        public string $compress = '',
    ) {
    }

    public static function fromRequest(RequestData $request): static
    {
        foreach (['database', 'files', 'mysql', 'postgres'] as $field) {
            if (!is_bool($request->body[$field] ?? null)) {
                throw new RequestValidationException('Некорректные параметры создания бэкапа.');
            }
        }
        $location = $request->body['location'] ?? null;
        $strategy = $request->body['strategy'] ?? '';
        $compress = $request->body['compress'] ?? '';
        if (!is_string($location) || ($location !== '' && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $location) !== 1)) {
            throw new RequestValidationException('Некорректное расположение бэкапа.');
        }
        if (!is_string($strategy) || ($strategy !== '' && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $strategy) !== 1)
            || !is_string($compress) || !in_array($compress, ['', 'gzip', 'bzip2', 'xz', 'zstd', 'lz4', 'zip'], true)) {
            throw new RequestValidationException('Некорректные параметры файлового бэкапа.');
        }

        return new static(
            rawurldecode($request->route['name']),
            $request->body['database'],
            $request->body['files'],
            $request->body['mysql'],
            $request->body['postgres'],
            $location,
            $strategy,
            $compress,
        );
    }
}
