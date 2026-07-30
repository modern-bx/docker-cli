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
        public ?string $status,
        public ?string $queueItem,
        public ?string $itemCode,
        public ?string $taskCode,
        public ?string $level,
        public ?string $context,
    ) {
    }

    public static function fromRequest(RequestData $request): static
    {
        $page = filter_var($request->query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $pageSize = filter_var($request->query['pageSize'] ?? 25, FILTER_VALIDATE_INT);
        $sort = (string) ($request->query['sort'] ?? 'timestamp');
        $direction = (string) ($request->query['direction'] ?? 'desc');
        $allowedSort = ['timestamp', 'queueItem', 'itemCode', 'project', 'queueCode', 'status', 'taskCode', 'level', 'context', 'result', 'message'];
        if ($page === false || !in_array($pageSize, [25, 50, 100], true) || !in_array($sort, $allowedSort, true) || !in_array($direction, ['asc', 'desc'], true)) {
            throw new RequestValidationException('Некорректные параметры журнала.');
        }
        $project = isset($request->query['project']) && is_string($request->query['project']) && $request->query['project'] !== ''
            ? $request->query['project']
            : null;
        $status = isset($request->query['status']) && is_string($request->query['status']) && in_array($request->query['status'], \DockerCli\Queue\QueueRepository::STATUSES, true)
            ? $request->query['status']
            : null;
        $text = static fn (string $field): ?string => isset($request->query[$field]) && is_string($request->query[$field]) && trim($request->query[$field]) !== ''
            ? trim($request->query[$field])
            : null;
        $level = isset($request->query['level']) && is_string($request->query['level']) && in_array($request->query['level'], ['debug', 'info', 'warning', 'error'], true) ? $request->query['level'] : null;
        $context = isset($request->query['context']) && is_string($request->query['context']) && in_array($request->query['context'], ['command', 'task', 'queue'], true) ? $request->query['context'] : null;
        return new static($page, $pageSize, $sort, $direction, $project, $status, $text('queueItem'), $text('itemCode'), $text('taskCode'), $level, $context);
    }
}
