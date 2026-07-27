<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Queue\QueueRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class QueueRunCommand extends Command
{
    private const POLL_INTERVAL_MICROSECONDS = 1_000_000;

    public function __construct(private readonly ?QueueRepository $queues = null)
    {
        parent::__construct('queue:run');
        $this->setDescription('Непрерывно обрабатывать элементы очереди.');
        $this->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Код очереди.', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queue = (string) $input->getOption('queue');
        $repository = $this->queues ?? new QueueRepository();
        $repository->initialize($queue);
        $runner = new QueueStepCommand($repository);
        $runner->setApplication($this->getApplication());
        $quietOutput = new NullOutput();

        while (true) {
            $runner->run(new ArrayInput(['--queue' => $queue]), $quietOutput);
            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }
    }
}
