<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class ProjectBackupListDto implements \JsonSerializable
{
    /** @param list<array{name: string, date: string, composition: string, size: int, database: string|null, databaseCode: string, strategy: string|null, strategyCode: string, strategyPaths: array{include: list<string>, exclude: list<string>}|null, hasDatabase: bool, hasFiles: bool, location: string, locationName: string}> $items */
    public function __construct(public array $items, public int $total, public int $page, public int $pageSize)
    {
    }

    public function jsonSerialize(): array
    {
        return ['items' => $this->items, 'total' => $this->total, 'page' => $this->page, 'pageSize' => $this->pageSize];
    }
}
