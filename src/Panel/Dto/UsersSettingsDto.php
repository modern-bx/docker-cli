<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class UsersSettingsDto implements \JsonSerializable
{
    /** @param list<array{login: string, comments: string}> $users */
    public function __construct(public array $users, public int $total, public int $page, public int $pageSize, public ?string $password = null, public bool $logout = false)
    {
    }

    public function jsonSerialize(): array
    {
        return ['users' => $this->users, 'total' => $this->total, 'page' => $this->page, 'pageSize' => $this->pageSize, 'password' => $this->password, 'logout' => $this->logout];
    }
}
