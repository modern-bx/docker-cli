<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Queue\QueueRepository;
use DockerCli\Queue\SystemdService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class QueueStartCommand extends Command
{
    private const POLL_INTERVAL_MICROSECONDS = 1_000_000;

    public function __construct(
        private readonly ?QueueRepository $queues = null,
        private readonly SystemdService $systemdService = new SystemdService(),
    ) {
        parent::__construct('queue:start');
        $this->setDescription('Непрерывно обрабатывать элементы очереди.');
        $this->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Код очереди.', 'default');
        $this->addOption('daemon', 'd', InputOption::VALUE_NONE, 'Создать и запустить systemd-сервис очереди.');
        $this->addOption('user', null, InputOption::VALUE_REQUIRED, 'Пользователь, от имени которого будет работать systemd-сервис.');
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Явный путь к бинарнику для systemd-сервиса.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queue = (string) $input->getOption('queue');
        $repository = $this->queues ?? new QueueRepository();
        // Validate the queue code before installing a service, without requiring
        // the target service user's configuration to exist yet.
        $repository->queueDirectory($queue);
        if ($input->getOption('daemon')) {
            return $this->installSystemdService($input, $output, $queue);
        }
        if ($input->getOption('user') !== null || $input->getOption('path') !== null) {
            $output->writeln('<error>Опции --user и --path можно использовать только вместе с -d.</error>');
            return Command::INVALID;
        }

        $repository->initialize($queue);
        $runner = new QueueStepCommand($repository);
        $runner->setApplication($this->getApplication());
        $quietOutput = new NullOutput();

        while (true) {
            $runner->run(new ArrayInput(['--queue' => $queue]), $quietOutput);
            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }
    }

    private function installSystemdService(InputInterface $input, OutputInterface $output, string $queue): int
    {
        $rawUser = $input->getOption('user');
        if ($rawUser !== null && (!is_string($rawUser) || preg_match('/^[a-zA-Z0-9_.@-]+$/D', $rawUser) !== 1)) {
            $output->writeln('<error>Некорректное имя пользователя для systemd-сервиса.</error>');
            return Command::INVALID;
        }

        $rawPath = $input->getOption('path');
        $binary = $this->resolveBinary(is_string($rawPath) ? $rawPath : (string) ($_SERVER['argv'][0] ?? 'docker-cli'));
        if ($rawPath !== null && (!str_starts_with($binary, DIRECTORY_SEPARATOR) || !is_file($binary) || !is_executable($binary))) {
            $output->writeln('<error>Опция --path должна указывать на существующий исполняемый файл.</error>');
            return Command::INVALID;
        }

        try {
            $this->systemdService->install($queue, $binary, is_string($rawUser) ? $rawUser : null);
        } catch (\RuntimeException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $name = $this->systemdService->name($queue);
        $output->writeln(sprintf('<info>Создан systemd-сервис %s.</info>', $name));
        $output->writeln(sprintf('<info>Конфигурация записана в %s.</info>', $this->systemdService->unitPath($queue)));
        $output->writeln(sprintf('<info>Сервис запускает: %s queue:start --queue=%s</info>', $binary, $queue));
        if (is_string($rawUser)) {
            $output->writeln(sprintf('<info>Сервис работает от пользователя: %s</info>', $rawUser));
        }
        $output->writeln(sprintf('<info>Сервис включён и запущен. Управление: systemctl {status|restart|stop} %s</info>', $name));

        return Command::SUCCESS;
    }

    private function resolveBinary(string $binary): string
    {
        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            return realpath($binary) ?: $binary;
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        return $binary;
    }
}
