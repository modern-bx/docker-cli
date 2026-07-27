<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Panel\SystemdService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PanelDownCommand extends Command
{
    public function __construct(private readonly SystemdService $service = new SystemdService())
    {
        parent::__construct('panel:down');
        $this->setDescription('Остановить и удалить systemd-сервис административной панели.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->service->remove();
        } catch (\RuntimeException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Сервис %s остановлен и отключён.</info>', SystemdService::NAME));
        $output->writeln(sprintf('<info>Файл конфигурации %s удалён.</info>', SystemdService::UNIT_PATH));
        $output->writeln('<info>Конфигурация systemd перечитана.</info>');

        return Command::SUCCESS;
    }
}
