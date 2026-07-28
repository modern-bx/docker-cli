<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class LogRequestDto implements RequestDto
{
    public function __construct(
        public int $page,
        public int $pageSize,
        public string $sort,
        public string $direction,
        public ?string $project,
        public ?string $queueItem,
        public ?string $itemCode,
        public ?string $taskCode,
    ) {
    }

    public static function fromRequest(RequestData $request): static
    {
        $page = filter_var($request->query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $pageSize = filter_var($request->query['pageSize'] ?? 25, FILTER_VALIDATE_INT);
        $sort = (string) ($request->query['sort'] ?? 'timestamp');
        $direction = (string) ($request->query['direction'] ?? 'desc');
        $allowedSort = ['timestamp', 'queueItem', 'itemCode', 'project', 'queueCode', 'taskCode', 'result', 'message'];
        if ($page === false || !in_array($pageSize, [25, 50, 100], true) || !in_array($sort, $allowedSort, true) || !in_array($direction, ['asc', 'desc'], true)) {
            throw new RequestValidationException('Некорректные параметры журнала.');
        }
        $project = isset($request->query['project']) && is_string($request->query['project']) && $request->query['project'] !== ''
            ? $request->query['project']
            : null;
        $text = static fn (string $field): ?string => isset($request->query[$field]) && is_string($request->query[$field]) && trim($request->query[$field]) !== ''
            ? trim($request->query[$field])
            : null;
        return new static($page, $pageSize, $sort, $direction, $project, $text('queueItem'), $text('itemCode'), $text('taskCode'));
    }
}
