<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Queue\QueueItemValidator;
use DockerCli\Queue\QueueRepository;
use DockerCli\Task\TaskRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class QueueItemCreateCommand extends AbstractCommand
{
    public function __construct(private readonly ?QueueRepository $queues = null, private readonly ?TaskRepository $tasks = null)
    {
        parent::__construct('queue:item-create');
        $this->setDescription('Создать элемент очереди, содержащий одну задачу.');
        $this->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Код очереди.', 'default');
        $this->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Режим создания элемента (task).');
        $this->addOption('task', null, InputOption::VALUE_REQUIRED, 'Код задачи.');
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Код проекта для задачи с context: project.');
        $this->addArgument('task-args', InputArgument::IS_ARRAY, 'Значения параметров: name=value или позиционные значения в порядке спеки.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            if ($input->getOption('mode') !== 'task') {
                throw new \InvalidArgumentException('Опция --mode должна иметь значение "task".');
            }
            $code = $input->getOption('task');
            if (!is_string($code) || $code === '') {
                throw new \InvalidArgumentException('Необходимо указать непустую опцию --task.');
            }

            $tasks = $this->tasks ?? new TaskRepository();
            $spec = $tasks->find($code)['task'];
            $arguments = $this->mapArguments($spec['parameters'] ?? [], $input->getArgument('task-args'));
            $task = ['code' => $code, 'arguments' => $arguments];
            $project = $input->getOption('project');
            if (is_string($project) && $project !== '') {
                $task['project'] = $project;
            }
            $item = [
                'meta' => ['schema' => 'queue-item', 'version' => '0.1'],
                'queue-item' => ['tasks' => [$task]],
            ];
            $errors = (new QueueItemValidator($tasks))->validate($item);
            if ($errors !== []) {
                throw new \InvalidArgumentException(implode("\n", $errors));
            }

            $queue = (string) $input->getOption('queue');
            $file = ($this->queues ?? new QueueRepository())->create($queue, $code, $item);
        } catch (\Throwable $exception) {
            $this->writeMessage($output, '<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        }

        $this->writeMessage($output, sprintf('<info>Элемент очереди создан: %s</info>', $file));
        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $parameters @param list<mixed> $arguments @return array<string, array{value: string|int|bool}> */
    private function mapArguments(array $parameters, array $arguments): array
    {
        $named = [];
        $positional = [];
        foreach ($arguments as $argument) {
            $argument = (string) $argument;
            if (str_contains($argument, '=')) {
                [$name, $value] = explode('=', $argument, 2);
                if (!array_key_exists($name, $parameters)) {
                    throw new \InvalidArgumentException(sprintf('Неизвестный параметр "%s".', $name));
                }
                if (array_key_exists($name, $named)) {
                    throw new \InvalidArgumentException(sprintf('Параметр "%s" передан повторно.', $name));
                }
                $named[$name] = $value;
            } else {
                $positional[] = $argument;
            }
        }

        $result = [];
        foreach ($parameters as $name => $spec) {
            if (array_key_exists($name, $named)) {
                $value = $named[$name];
            } elseif ($positional !== []) {
                $value = array_shift($positional);
            } else {
                continue;
            }
            if (($spec['type'] ?? null) === 'integer' && filter_var($value, FILTER_VALIDATE_INT) !== false) {
                $value = (int) $value;
            } elseif (($spec['type'] ?? null) === 'boolean') {
                $boolean = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($boolean !== null) $value = $boolean;
            }
            $result[$name] = ['value' => $value];
        }
        if ($positional !== []) {
            throw new \InvalidArgumentException('Переданы лишние позиционные аргументы.');
        }
        return $result;
    }
}
