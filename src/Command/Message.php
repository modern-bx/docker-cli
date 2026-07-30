<?php

declare(strict_types=1);

namespace DockerCli\Command;

final class Message
{
    private string $message;
    private MessageLevel $level;
    private ?string $origin = null;
    private ?string $class = null;
    private bool $notify;
    private bool $console = true;

    public function __construct(string $message, MessageLevel|string $level = 'info', bool $notify = false)
    {
        $this->message = $message;
        $this->level = is_string($level) ? MessageLevel::from($level) : $level;
        $this->notify = $notify;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    public function getLevel(): MessageLevel
    {
        return $this->level;
    }

    public function setLevel(MessageLevel|string $level): self
    {
        $this->level = is_string($level) ? MessageLevel::from($level) : $level;

        return $this;
    }

    public function getOrigin(): ?string
    {
        return $this->origin;
    }

    public function setOrigin(string $origin): self
    {
        $this->origin = $origin;

        return $this;
    }

    public function getClass(): ?string
    {
        return $this->class;
    }

    public function setClass(string $class): self
    {
        $this->class = $class;

        return $this;
    }

    public function getNotify(): bool
    {
        return $this->notify;
    }

    public function setNotify(bool $notify): self
    {
        $this->notify = $notify;

        return $this;
    }

    public function getConsole(): bool
    {
        return $this->console;
    }

    public function setConsole(bool $console): self
    {
        $this->console = $console;

        return $this;
    }
}
