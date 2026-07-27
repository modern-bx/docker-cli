<?php

declare(strict_types=1);

namespace DockerCli\Panel\Http;

use DockerCli\Panel\Dto\ErrorResponseDto;
use DockerCli\Panel\Dto\FileResponseDto;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

final readonly class ResponseEmitter
{
    public function __construct(private string $assetsDirectory)
    {
    }

    public function emit(object $dto): ResponseInterface
    {
        return $dto instanceof FileResponseDto ? $this->file($dto) : $this->json(200, $dto);
    }

    public function json(int $status, \JsonSerializable $dto): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store'], json_encode($dto, JSON_THROW_ON_ERROR));
    }

    private function file(FileResponseDto $dto): ResponseInterface
    {
        if (str_contains($dto->relativePath, '..')) {
            return $this->json(404, new ErrorResponseDto('Страница не найдена.'));
        }
        $file = $this->assetsDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dto->relativePath);
        if (!is_file($file) || ($contents = file_get_contents($file)) === false) {
            return $this->json(404, new ErrorResponseDto('Ресурс не найден.'));
        }
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        $contentType = match ($extension) {
            'html' => 'text/html; charset=UTF-8',
            'js' => 'text/javascript; charset=UTF-8',
            'css' => 'text/css; charset=UTF-8',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };

        return new Response(200, ['Content-Type' => $contentType, 'Cache-Control' => $extension === 'html' ? 'no-store' : 'public, max-age=31536000, immutable'], $contents);
    }
}
