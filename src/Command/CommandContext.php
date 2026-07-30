<?php

declare(strict_types=1);

namespace DockerCli\Command;

use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class CommandContext
{
    public const FILE_ENVIRONMENT_VARIABLE = 'DOCKER_CLI_COMMAND_CONTEXT_FILE';

    /** @var list<array{origin: string, class: string, level: string, message: string, notify: bool, timestamp: string}> */
    private array $notifications = [];

    public function __construct(
        private readonly ?string $transportFile = null,
        private readonly ?ContextUser $contextUser = null,
        private readonly ?OutputInterface $output = null,
    )
    {
    }

    public static function fromEnvironment(ContextUser $contextUser, ?OutputInterface $output = null): self
    {
        $file = getenv(self::FILE_ENVIRONMENT_VARIABLE);

        return new self(is_string($file) && $file !== '' ? $file : null, $contextUser, $output ?? new ConsoleOutput());
    }

    public function addMessage(Message $message): void
    {
        $level = $message->getLevel()->value;
        if ($message->getConsole()) {
            $consoleLevel = in_array($message->getLevel(), [MessageLevel::Debug, MessageLevel::Warning], true) ? MessageLevel::Comment->value : $level;
            $this->output?->writeln(sprintf('<%1$s>%2$s</%1$s>', $consoleLevel, $message->getMessage()));
        }
        $origin = $message->getOrigin() ?? $this->contextUser?->getOrigin() ?? 'unknown';
        $class = $message->getClass() ?? $this->contextUser?->getClass() ?? 'unknown';
        $message->setOrigin($origin)->setClass($class);
        $notify = $message->getNotify();
        $message = $message->getMessage();
        $timestamp = sprintf('%.6f', microtime(true));
        $record = compact('origin', 'class', 'level', 'message', 'notify', 'timestamp');
        if ($notify) {
            $this->notifications[] = $record;
        }
        if ($this->transportFile !== null
            && file_put_contents($this->transportFile, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось передать сообщение из команды.');
        }
    }

    /** @return list<array{origin: string, class: string, level: string, message: string, notify: bool, timestamp: string}> */
    public function notifications(): array
    {
        return $this->notifications;
    }

    /** @return list<array{origin: string, class: string, level: string, message: string, notify: bool, timestamp: string}> */
    public static function read(string $file): array
    {
        $notifications = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $notification = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($notification)) throw new \RuntimeException('Некорректное сообщение команды.');
            foreach (['origin', 'class', 'level', 'message'] as $field) {
                if (!is_string($notification[$field] ?? null)) throw new \RuntimeException('Некорректное сообщение команды.');
            }
            $notification['notify'] ??= true;
            $notification['timestamp'] ??= sprintf('%.6f', microtime(true));
            if (!is_bool($notification['notify']) || !is_string($notification['timestamp'])) {
                throw new \RuntimeException('Некорректное сообщение команды.');
            }
            $notifications[] = $notification;
        }

        return $notifications;
    }
}
