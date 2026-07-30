<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class ProjectBackupListRequestDto implements RequestDto
{
    public function __construct(public string $name, public int $page, public int $pageSize, public string $backupName, public string $composition, public string $database, public ?string $dateFrom, public ?string $dateTo, public string $sort, public string $direction)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $page = filter_var($request->query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $pageSize = filter_var($request->query['pageSize'] ?? 25, FILTER_VALIDATE_INT);
        $name = $request->query['name'] ?? '';
        $composition = $request->query['composition'] ?? 'all';
        $database = $request->query['database'] ?? 'all';
        $dateFrom = self::date($request->query['dateFrom'] ?? '');
        $dateTo = self::date($request->query['dateTo'] ?? '');
        $sort = $request->query['sort'] ?? 'date';
        $direction = $request->query['direction'] ?? 'desc';
        if ($page === false || !in_array($pageSize, [25, 50, 100], true) || !is_string($name) || mb_strlen($name) > 500 || !in_array($composition, ['all', 'database', 'files', 'database-files'], true) || !in_array($database, ['all', 'mysql'], true) || $dateFrom === false || $dateTo === false || ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) || !in_array($sort, ['name', 'date', 'composition', 'size', 'database'], true) || !in_array($direction, ['asc', 'desc'], true)) {
            throw new RequestValidationException('Некорректные параметры списка бэкапов.');
        }
        return new static(rawurldecode($request->route['name']), $page, $pageSize, trim($name), $composition, $database, $dateFrom, $dateTo, $sort, $direction);
    }

    private static function date(mixed $value): string|null|false
    {
        if ($value === '') return null;
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) return false;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : false;
    }
}
