<?php

declare(strict_types=1);

namespace DockerCli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class CommandContext
{
    public const FILE_ENVIRONMENT_VARIABLE = 'DOCKER_CLI_COMMAND_CONTEXT_FILE';

    /** @var list<array{origin: string, class: string, level: string, message: string}> */
    private array $notifications = [];

    public function __construct(
        private readonly ?string $transportFile = null,
        private readonly ?Command $command = null,
        private readonly ?OutputInterface $output = null,
    )
    {
    }

    public static function fromEnvironment(Command $command): self
    {
        $file = getenv(self::FILE_ENVIRONMENT_VARIABLE);

        return new self(is_string($file) && $file !== '' ? $file : null, $command, new ConsoleOutput());
    }

    public function addMessage(Message $message): void
    {
        $level = $message->getLevel()->value;
        if ($message->getConsole()) {
            $this->output?->writeln(sprintf('<%1$s>%2$s</%1$s>', $level, $message->getMessage()));
        }
        if (!$message->getNotify()) {
            return;
        }

        $origin = $message->getOrigin() ?? str_replace(':', '.', $this->command?->getName() ?? 'unknown');
        $class = $message->getClass() ?? 'command';
        $message->setOrigin($origin)->setClass($class);
        $message = $message->getMessage();
        $notification = compact('origin', 'class', 'level', 'message');
        $this->notifications[] = $notification;
        if ($this->transportFile !== null
            && file_put_contents($this->transportFile, json_encode($notification, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось передать уведомление из команды.');
        }
    }

    /** @return list<array{origin: string, class: string, level: string, message: string}> */
    public function notifications(): array
    {
        return $this->notifications;
    }

    /** @return list<array{origin: string, class: string, level: string, message: string}> */
    public static function read(string $file): array
    {
        $notifications = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $notification = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($notification)) throw new \RuntimeException('Некорректное уведомление команды.');
            foreach (['origin', 'class', 'level', 'message'] as $field) {
                if (!is_string($notification[$field] ?? null)) throw new \RuntimeException('Некорректное уведомление команды.');
            }
            $notifications[] = $notification;
        }

        return $notifications;
    }
}
