<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Queue\QueueRepository;
use DockerCli\Queue\SystemdService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class QueueStopCommand extends AbstractCommand
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
            $this->writeMessage($output, '<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $this->writeMessage($output, sprintf('<info>Сервис %s остановлен и отключён.</info>', $this->service->name($queue)));
        $this->writeMessage($output, sprintf('<info>Файл конфигурации %s удалён.</info>', $this->service->unitPath($queue)));
        $this->writeMessage($output, '<info>Конфигурация systemd перечитана.</info>');

        return Command::SUCCESS;
    }
}
