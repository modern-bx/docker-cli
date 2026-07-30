<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Queue\QueueRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class QueuePauseCommand extends AbstractCommand
{
    public function __construct(private readonly ?QueueRepository $queues = null)
    {
        parent::__construct('queue:pause');
        $this->setDescription('Приостановить выборку новых элементов из очереди.');
        $this->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Код очереди.', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queue = (string) $input->getOption('queue');
        try {
            ($this->queues ?? new QueueRepository())->pause($queue);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $this->writeMessage($output, '<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $this->writeMessage($output, sprintf('<info>Очередь "%s" приостановлена.</info>', $queue));
        return Command::SUCCESS;
    }
}
