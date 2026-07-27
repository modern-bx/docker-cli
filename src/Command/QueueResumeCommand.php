<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Queue\QueueRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class QueueResumeCommand extends Command
{
    public function __construct(private readonly ?QueueRepository $queues = null)
    {
        parent::__construct('queue:resume');
        $this->setDescription('Возобновить выборку новых элементов из очереди.');
        $this->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Код очереди.', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queue = (string) $input->getOption('queue');
        try {
            ($this->queues ?? new QueueRepository())->resume($queue);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $output->writeln(sprintf('<info>Очередь "%s" возобновлена.</info>', $queue));
        return Command::SUCCESS;
    }
}
