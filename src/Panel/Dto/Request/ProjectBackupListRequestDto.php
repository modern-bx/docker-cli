<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class ProjectBackupListRequestDto implements RequestDto
{
    public function __construct(public string $name, public int $page, public int $pageSize, public string $filter, public string $sort, public string $direction)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $page = filter_var($request->query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $pageSize = filter_var($request->query['pageSize'] ?? 25, FILTER_VALIDATE_INT);
        $filter = $request->query['filter'] ?? '';
        $sort = $request->query['sort'] ?? 'date';
        $direction = $request->query['direction'] ?? 'desc';
        if ($page === false || !in_array($pageSize, [25, 50, 100], true) || !is_string($filter) || mb_strlen($filter) > 500 || !in_array($sort, ['name', 'date', 'composition', 'size', 'database'], true) || !in_array($direction, ['asc', 'desc'], true)) {
            throw new RequestValidationException('Некорректные параметры списка бэкапов.');
        }
        return new static(rawurldecode($request->route['name']), $page, $pageSize, trim($filter), $sort, $direction);
    }
}
