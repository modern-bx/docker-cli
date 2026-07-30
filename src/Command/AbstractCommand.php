<?php

declare(strict_types=1);

namespace DockerCli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractCommand extends Command implements ContextUser
{
    public function getOrigin(): string
    {
        return str_replace(':', '.', $this->getName() ?? 'unknown');
    }

    public function getClass(): string
    {
        return 'command';
    }

    protected function writeMessage(OutputInterface $output, string $message): void
    {
        $level = MessageLevel::Info;
        if (preg_match('/^<(info|comment|error)>(.*)<\/\1>$/s', $message, $matches) === 1) {
            $level = MessageLevel::from($matches[1]);
            $message = $matches[2];
        }

        CommandContext::fromEnvironment($this, $output)->addMessage(new Message($message, $level));
    }
}
