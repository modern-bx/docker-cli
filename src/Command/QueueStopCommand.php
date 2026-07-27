<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Queue\QueueRepository;
use DockerCli\Queue\SystemdService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class QueueStopCommand extends Command
{
    public function __construct(
        private readonly SystemdService $service = new SystemdService(),
        private readonly ?QueueRepository $queues = null,
    ) {
        parent::__construct('queue:stop');
        $this->setDescription('Остановить и удалить systemd-сервис очереди.');
        $this->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Код очереди.', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queue = (string) $input->getOption('queue');
        ($this->queues ?? new QueueRepository())->queueDirectory($queue);

        try {
            $this->service->remove($queue);
        } catch (\RuntimeException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Сервис %s остановлен и отключён.</info>', $this->service->name($queue)));
        $output->writeln(sprintf('<info>Файл конфигурации %s удалён.</info>', $this->service->unitPath($queue)));
        $output->writeln('<info>Конфигурация systemd перечитана.</info>');

        return Command::SUCCESS;
    }
}
