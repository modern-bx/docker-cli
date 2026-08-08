<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use DockerCli\Panel\Dto\FileResponseDto;
use DockerCli\Panel\Dto\Request\AssetRequestDto;
use DockerCli\Panel\Http\Attribute\Route;

final readonly class AssetController
{
    #[Route('GET', '/', AssetRequestDto::class, FileResponseDto::class, authenticated: false)]
    public function index(AssetRequestDto $request): FileResponseDto
    {
        return new FileResponseDto('index.html');
    }

    #[Route('GET', '/favicon.svg', AssetRequestDto::class, FileResponseDto::class, authenticated: false)]
    public function favicon(AssetRequestDto $request): FileResponseDto
    {
        return new FileResponseDto('favicon.svg');
    }

    #[Route('GET', '/assets/{path:.+}', AssetRequestDto::class, FileResponseDto::class, authenticated: false)]
    public function asset(AssetRequestDto $request): FileResponseDto
    {
        return new FileResponseDto('assets/' . $request->path);
    }
}
