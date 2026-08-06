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
        public string $chunkSize = '',
        public string $chunkCount = '',
        public string $comment = '',
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
        $chunkSize = $request->body['chunkSize'] ?? '';
        $chunkCount = $request->body['chunkCount'] ?? '';
        $comment = $request->body['comment'] ?? '';
        if (!is_string($comment)) {
            throw new RequestValidationException('Некорректный комментарий к бэкапу.');
        }
        if (!is_string($location) || ($location !== '' && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $location) !== 1)) {
            throw new RequestValidationException('Некорректное расположение бэкапа.');
        }
        if (!is_string($strategy) || ($strategy !== '' && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $strategy) !== 1)
            || !is_string($compress) || !in_array($compress, ['', 'gzip', 'bzip2', 'xz', 'zstd', 'lz4', 'zip'], true)) {
            throw new RequestValidationException('Некорректные параметры файлового бэкапа.');
        }
        if (!is_string($chunkSize) || !is_string($chunkCount) || ($chunkSize !== '' && $chunkCount !== '')
            || ($chunkSize !== '' && preg_match('/^\d+(?:\.\d+)?(?:B|K|M|G)?$/i', $chunkSize) !== 1)
            || ($chunkCount !== '' && (preg_match('/^\d+$/', $chunkCount) !== 1 || (int) $chunkCount < 2))) {
            throw new RequestValidationException('Укажите либо корректный размер тома, либо количество томов не меньше двух.');
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
            $chunkSize,
            $chunkCount,
            trim($comment),
        );
    }
}
