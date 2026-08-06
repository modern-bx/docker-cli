<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class ProjectBackupCommentRequestDto implements RequestDto
{
    public function __construct(public string $name, public string $backup, public string $location, public string $comment)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $location = $request->body['location'] ?? null;
        $comment = $request->body['comment'] ?? null;
        if (!is_string($location) || !is_string($comment)) {
            throw new RequestValidationException('Некорректный комментарий к бэкапу.');
        }

        $backup = rawurldecode($request->route['backup']);
        if ($backup === '' || basename($backup) !== $backup || in_array($backup, ['.', '..'], true)
            || ($location !== '' && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $location) !== 1)) {
            throw new RequestValidationException('Некорректные параметры бэкапа.');
        }

        return new static(rawurldecode($request->route['name']), $backup, $location, trim($comment));
    }
}
