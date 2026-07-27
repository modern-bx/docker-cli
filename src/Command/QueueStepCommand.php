<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Queue\QueueItemValidator;
use DockerCli\Queue\QueueRepository;
use DockerCli\Task\TaskRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use function DockerCli\Util\join_path;

final class QueueStepCommand extends Command
{
    public function __construct(private readonly ?QueueRepository $queues = null)
    {
        parent::__construct('queue:step');
        $this->setDescription('Обработать следующий элемент очереди.');
        $this->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Код очереди.', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queue = (string) $input->getOption('queue');
        $repository = $this->queues ?? new QueueRepository();
        try {
            $repository->initialize($queue);
            $lock = fopen(join_path($repository->queueDirectory($queue), '.lock'), 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                $output->writeln(sprintf('<error>Очередь "%s" уже обрабатывается.</error>', $queue));
                return Command::FAILURE;
            }
            if ($repository->isPaused($queue)) {
                $output->writeln(sprintf('<comment>Очередь "%s" приостановлена.</comment>', $queue));
                return Command::SUCCESS;
            }
            $pending = $repository->nextPending($queue);
            if ($pending === null) {
                $output->writeln(sprintf('<info>В очереди "%s" нет элементов для обработки.</info>', $queue));
                return Command::SUCCESS;
            }
            $active = $repository->move($pending, $queue, '20-active');
            $item = [];
            try {
                $parsed = Yaml::parseFile($active);
                $item = is_array($parsed) ? $parsed : [];
                $repository->trace($active, $queue, $item, 'Элемент перемещен из 10-pending в 20-active.');
                $tasks = new TaskRepository(join_path($repository->configDirectory(), 'tasks'));
                $errors = (new QueueItemValidator($tasks))->validate($parsed);
            } catch (ParseException $exception) {
                $errors = ['Ошибка YAML: ' . $exception->getMessage()];
            }
            if ($errors !== []) {
                foreach ($errors as $error) {
                    $output->writeln('<error>' . $error . '</error>');
                    $repository->trace($active, $queue, $item, 'Ошибка валидации: ' . $error);
                }
                $repository->trace($active, $queue, $item, 'Элемент перемещен в 50-error.');
                $repository->move($active, $queue, '50-error');
                return Command::INVALID;
            }
            foreach ($item['queue-item']['tasks'] as $task) {
                $repository->trace($active, $queue, $item, sprintf('Запуск задачи %s.', $task['code']));
                $arguments = ['task-code' => $task['code']];
                if (isset($task['project'])) {
                    $arguments['--project'] = $task['project'];
                }
                $arguments['task-args'] = array_map(
                    static fn (mixed $value, string $name): string => $name . '=' . (is_scalar($value['value'] ?? null) ? (string) $value['value'] : json_encode($value['value'] ?? null)),
                    $task['arguments'] ?? [],
                    array_keys($task['arguments'] ?? []),
                );
                $runner = new TaskRunCommand($tasks);
                $runner->setApplication($this->getApplication());
                $exitCode = $runner->run(new ArrayInput($arguments), $output);
                $repository->trace($active, $queue, $item, sprintf('Задача %s завершилась с кодом %d.', $task['code'], $exitCode));
                if ($exitCode !== Command::SUCCESS) {
                    $repository->trace($active, $queue, $item, 'Элемент перемещен в 40-failure; последующие задачи пропущены.');
                    $repository->move($active, $queue, '40-failure');
                    return $exitCode;
                }
            }
            $repository->trace($active, $queue, $item, 'Элемент перемещен в 30-success.');
            $repository->move($active, $queue, '30-success');
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            if (isset($active, $item) && is_string($active) && is_file($active) && is_array($item)) {
                try {
                    $repository->trace($active, $queue, $item, 'Ошибка обработки: ' . $exception->getMessage());
                    $repository->trace($active, $queue, $item, 'Элемент перемещен в 50-error.');
                    $repository->move($active, $queue, '50-error');
                } catch (\Throwable) {
                    // Preserve the original processing error.
                }
            }
            return Command::FAILURE;
        } finally {
            if (isset($lock) && is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}
