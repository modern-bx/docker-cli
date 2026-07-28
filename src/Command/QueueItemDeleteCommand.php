<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Queue\QueueRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class QueueItemDeleteCommand extends Command
{
    public function __construct(private readonly ?QueueRepository $queues = null)
    {
        parent::__construct('queue:item-delete');
        $this->setDescription('Удалить неактивный элемент очереди.');
        $this->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Код очереди.', 'default');
        $this->addArgument('item', InputArgument::REQUIRED, 'Короткое имя элемента с расширением .yaml или без него.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queue = (string) $input->getOption('queue');
        $item = (string) $input->getArgument('item');
        if (!str_ends_with($item, '.yaml')) {
            $item .= '.yaml';
        }

        try {
            ($this->queues ?? new QueueRepository())->delete($queue, $item);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Элемент "%s" удалён из очереди "%s".</info>', $item, $queue));
        return Command::SUCCESS;
    }
}
