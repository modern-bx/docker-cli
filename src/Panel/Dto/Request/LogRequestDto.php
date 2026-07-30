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
        public array $projects,
        public array $statuses,
        public ?string $queueItem,
        public ?string $itemCode,
        public ?string $taskCode,
        public array $levels,
        public array $contexts,
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
        $selection = static function (string $field, array $allowed) use ($request): array {
            $value = $request->query[$field] ?? [];
            $values = is_array($value) ? $value : explode(',', is_string($value) ? $value : '');
            return array_values(array_unique(array_filter($values, static fn (mixed $item): bool => is_string($item) && in_array($item, $allowed, true))));
        };
        $projects = $selection('project', array_values(array_filter((array) ($request->query['project'] ?? []), 'is_string')));
        if (is_string($request->query['project'] ?? null)) {
            $projects = array_values(array_filter(explode(',', $request->query['project']), static fn (string $project): bool => $project !== ''));
        }
        $statuses = $selection('status', \DockerCli\Queue\QueueRepository::STATUSES);
        $text = static fn (string $field): ?string => isset($request->query[$field]) && is_string($request->query[$field]) && trim($request->query[$field]) !== ''
            ? trim($request->query[$field])
            : null;
        $levels = $selection('level', ['debug', 'info', 'warning', 'error']);
        $contexts = $selection('context', ['command', 'task', 'queue']);
        return new static($page, $pageSize, $sort, $direction, $projects, $statuses, $text('queueItem'), $text('itemCode'), $text('taskCode'), $levels, $contexts);
    }
}
