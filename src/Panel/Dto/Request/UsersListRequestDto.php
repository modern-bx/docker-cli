<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class UsersListRequestDto implements RequestDto
{
    public function __construct(public int $page, public int $pageSize)
    {
    }

    public static function fromRequest(RequestData $request): static
    {
        $page = filter_var($request->query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $pageSize = filter_var($request->query['pageSize'] ?? 25, FILTER_VALIDATE_INT);
        if ($page === false || !in_array($pageSize, [25, 50, 100], true)) {
            throw new RequestValidationException('Некорректные параметры страницы.');
        }
        return new static($page, $pageSize);
    }
}
