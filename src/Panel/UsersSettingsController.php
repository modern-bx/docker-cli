<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use DockerCli\Panel\Dto\Request\UsersListRequestDto;
use DockerCli\Panel\Dto\Request\UsersSettingsRequestDto;
use DockerCli\Panel\Dto\UsersSettingsDto;
use DockerCli\Panel\Http\Attribute\Route;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class UsersSettingsController
{
    public function __construct(private UserRepository $users, private TokenRepository $tokens, private PanelPasswordGenerator $passwords)
    {
    }

    #[Route('GET', '/api/settings/users', UsersListRequestDto::class, UsersSettingsDto::class)]
    public function list(UsersListRequestDto $request): UsersSettingsDto
    {
        return $this->response($request->page, $request->pageSize);
    }

    #[Route('POST', '/api/settings/users', UsersSettingsRequestDto::class, UsersSettingsDto::class)]
    public function create(UsersSettingsRequestDto $request): UsersSettingsDto
    {
        $password = $this->passwords->generate();
        if (!$this->users->add($request->login, $password, $request->comments)) {
            throw new RequestValidationException('Пользователь уже существует.');
        }
        return $this->response(password: $password);
    }

    #[Route('POST', '/api/settings/users/{login}', UsersSettingsRequestDto::class, UsersSettingsDto::class)]
    public function update(UsersSettingsRequestDto $request): UsersSettingsDto
    {
        if (!$this->users->updateComments($request->login, $request->comments)) throw new RequestValidationException('Пользователь не найден.');
        return $this->response();
    }

    #[Route('POST', '/api/settings/users/{login}/password', UsersSettingsRequestDto::class, UsersSettingsDto::class)]
    public function password(UsersSettingsRequestDto $request): UsersSettingsDto
    {
        $password = $this->passwords->generate();
        if (!$this->users->rotatePassword($request->login, $password)) throw new RequestValidationException('Пользователь не найден.');
        $this->tokens->revoke([$request->login]);
        return $this->response(password: $password, logout: $request->login === $request->currentLogin);
    }

    #[Route('DELETE', '/api/settings/users/{login}', UsersSettingsRequestDto::class, UsersSettingsDto::class)]
    public function delete(UsersSettingsRequestDto $request): UsersSettingsDto
    {
        if (!$this->users->delete($request->login)) throw new RequestValidationException('Пользователь не найден.');
        $this->tokens->revoke([$request->login]);
        return $this->response(logout: $request->login === $request->currentLogin);
    }

    private function response(int $page = 1, int $pageSize = 25, ?string $password = null, bool $logout = false): UsersSettingsDto
    {
        $users = $this->users->users();
        $total = count($users);
        $page = min($page, max(1, (int) ceil($total / $pageSize)));
        return new UsersSettingsDto(array_slice($users, ($page - 1) * $pageSize, $pageSize), $total, $page, $pageSize, $password, $logout);
    }
}
