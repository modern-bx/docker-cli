<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use DockerCli\Panel\Dto\QueueItemDto;
use DockerCli\Panel\Dto\QueueStateDto;
use DockerCli\Panel\Dto\Request\EmptyRequestDto;
use DockerCli\Panel\Dto\Request\QueueItemRequestDto;
use DockerCli\Panel\Dto\Request\QueueActionRequestDto;
use DockerCli\Panel\Http\Attribute\Route;
use DockerCli\Queue\QueueRepository;

final readonly class QueueController
{
    private const QUEUE = 'default';

    public function __construct(private QueueRepository $queues)
    {
    }

    #[Route('GET', '/api/queue/default', EmptyRequestDto::class, QueueStateDto::class)]
    public function state(EmptyRequestDto $request): QueueStateDto
    {
        $items = array_map(
            static fn (array $item): QueueItemDto => new QueueItemDto($item['file'], $item['status'], $item['queuedAt'], $item['code']),
            $this->queues->items(self::QUEUE),
        );
        return new QueueStateDto(self::QUEUE, $this->queues->isPaused(self::QUEUE), $items);
    }

    #[Route('POST', '/api/queue/default/{action:pause|resume}', QueueActionRequestDto::class, QueueStateDto::class)]
    public function action(QueueActionRequestDto $request): QueueStateDto
    {
        try {
            if ($request->action === 'pause') {
                $this->queues->pause(self::QUEUE);
            } else {
                $this->queues->resume(self::QUEUE);
            }
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            throw new QueueActionException($exception->getMessage());
        }
        return $this->state(new EmptyRequestDto());
    }

    #[Route('DELETE', '/api/queue/default/{file}', QueueItemRequestDto::class, QueueStateDto::class)]
    public function delete(QueueItemRequestDto $request): QueueStateDto
    {
        try {
            $this->queues->delete(self::QUEUE, $request->file);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            throw new QueueActionException($exception->getMessage());
        }
        return $this->state(new EmptyRequestDto());
    }
}
