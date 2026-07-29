<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Notification\NotificationRepository;
use DockerCli\Project\ProjectRegistry;
use DockerCli\Task\TaskRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function DockerCli\Util\join_path;

final class TaskRunCommand extends Command
{
    public function __construct(
        private readonly ?TaskRepository $repository = null,
        private readonly ?ProjectRegistry $registry = null,
        private readonly ?NotificationRepository $notifications = null,
    ) {
        parent::__construct('task:run');
        $this->setDescription('Найти и выполнить пользовательскую задачу.');
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Код зарегистрированного проекта для задачи с context: project.');
        $this->addOption('no-delete', null, InputOption::VALUE_NONE, 'Не удалять скомпилированный временный скрипт после выполнения.');
        $this->addArgument('task-code', InputArgument::REQUIRED, 'Код задачи.');
        $this->addArgument('task-args', InputArgument::IS_ARRAY, 'Значения параметров: name=value или позиционные значения в порядке спеки.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $code = (string) $input->getArgument('task-code');
        try {
            $definition = ($this->repository ?? new TaskRepository())->find($code);
            $task = $definition['task'];
            $this->validateTask($task, $code);
            $values = $this->mapArguments($task['parameters'] ?? [], $input->getArgument('task-args'));
            $cwd = $this->workingDirectory($task, $input->getOption('project'));
            if (($task['context'] ?? null) === 'project') {
                $values['project'] = (string) $input->getOption('project');
            }
            $this->ensureDirectory($cwd);
            $script = $this->compileScript($task, $values);
            $scriptFile = tempnam($cwd, '.docker-cli-task-');
            if ($scriptFile === false || file_put_contents($scriptFile, $script) === false || !chmod($scriptFile, 0700)) {
                throw new \RuntimeException(sprintf('Не удалось создать временный скрипт в "%s".', $cwd));
            }
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::INVALID;
        }

        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $contextFile = tempnam('/tmp', '.docker-cli-command-context-');
        if ($contextFile === false) {
            $output->writeln('<error>Не удалось создать контекст выполнения команды.</error>');
            return Command::FAILURE;
        }
        $environment[CommandContext::FILE_ENVIRONMENT_VARIABLE] = $contextFile;
        foreach ($values as $name => $value) {
            $environment[$this->normalizeName($name)] = (string) $value;
        }

        try {
            $process = proc_open(['bash', $scriptFile], [STDIN, STDOUT, STDERR], $pipes, $cwd, $environment);
            if (!is_resource($process)) {
                $output->writeln('<error>Не удалось запустить bash.</error>');

                return Command::FAILURE;
            }

            $exitCode = proc_close($process);
            foreach (CommandContext::read($contextFile) as $notification) {
                ($this->notifications ?? new NotificationRepository())->create(
                    $notification['origin'], $notification['class'], $notification['level'], $notification['message'],
                );
            }

            return is_int($exitCode) ? $exitCode : Command::FAILURE;
        } finally {
            if ($input->getOption('no-delete')) {
                $output->writeln(sprintf('<comment>Скомпилированный скрипт сохранен: %s</comment>', $scriptFile));
            } else {
                @unlink($scriptFile);
            }
            @unlink($contextFile);
        }
    }

    /** @param array<string, mixed> $task */
    private function validateTask(array $task, string $code): void
    {
        foreach (['name', 'code', 'type', 'action'] as $key) {
            if (!isset($task[$key]) || !is_string($task[$key]) || $task[$key] === '') {
                throw new \RuntimeException(sprintf('В задаче "%s" отсутствует строковое поле task.%s.', $code, $key));
            }
        }
        if ($task['type'] !== 'shell') {
            throw new \RuntimeException(sprintf('Тип задачи "%s" не поддерживается; допустим только shell.', $task['type']));
        }
        if ($task['code'] !== $code) {
            throw new \RuntimeException('Код найденной задачи не совпадает с запрошенным.');
        }
        if (isset($task['parameters']) && !is_array($task['parameters'])) {
            throw new \RuntimeException('task.parameters должен быть объектом.');
        }
        if (isset($task['return'])) {
            $this->validateParameter('return', $task['return'], true);
        }
        $this->validateTags($task['tags'] ?? null, 'задачи');
        foreach ($task['parameters'] ?? [] as $name => $spec) {
            if (!is_string($name) || $name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $name) !== 1) {
                throw new \RuntimeException(sprintf('Некорректное имя параметра "%s".', (string) $name));
            }
            $this->validateParameter($name, $spec);
        }
    }

    private function validateTags(mixed $tags, string $owner): void
    {
        if ($tags === null) {
            return;
        }
        if (!is_array($tags)) {
            throw new \RuntimeException(sprintf('Теги %s должны быть списком.', $owner));
        }
        foreach ($tags as $tag) {
            if (!is_string($tag) || preg_match('/^[A-Za-z][A-Za-z0-9._-]*$/', $tag) !== 1) {
                throw new \RuntimeException(sprintf('Некорректный тег %s: "%s".', $owner, is_scalar($tag) ? (string) $tag : get_debug_type($tag)));
            }
        }
    }

    private function validateParameter(string $name, mixed $spec, bool $return = false): void
    {
        if (!is_array($spec) || !in_array($spec['type'] ?? null, ['string', 'integer', 'list'], true)) {
            throw new \RuntimeException(sprintf('Параметр "%s" имеет неподдерживаемый тип.', $name));
        }
        if ($return && $spec['type'] === 'list') {
            throw new \RuntimeException('Возвращаемый тип list не поддерживается.');
        }
        if (($spec['type'] ?? null) === 'list' && isset($spec['items']) && !is_array($spec['items'])) {
            throw new \RuntimeException(sprintf('Поле items list-параметра "%s" должно быть списком.', $name));
        }
        foreach (($spec['type'] ?? null) === 'list' ? ($spec['items'] ?? []) : [] as $item) {
            if (!is_array($item) || !array_key_exists('name', $item) || !array_key_exists('value', $item)) {
                throw new \RuntimeException(sprintf('Каждый элемент list-параметра "%s" должен содержать name и value.', $name));
            }
        }
    }

    /** @param array<string, mixed> $parameters @param list<mixed> $arguments @return array<string, string|int> */
    private function mapArguments(array $parameters, array $arguments): array
    {
        $named = [];
        $positional = [];
        foreach ($arguments as $argument) {
            $argument = (string) $argument;
            if (str_contains($argument, '=')) {
                [$name, $value] = explode('=', $argument, 2);
                if (!array_key_exists($name, $parameters)) {
                    throw new \RuntimeException(sprintf('Неизвестный параметр "%s".', $name));
                }
                $named[$name] = $value;
            } else {
                $positional[] = $argument;
            }
        }

        $values = [];
        foreach ($parameters as $name => $spec) {
            $value = $named[$name] ?? array_shift($positional);
            if ($value === null || $value === '') {
                if (($spec['required'] ?? false) === true) {
                    throw new \RuntimeException(sprintf('Обязательный параметр "%s" не передан.', $name));
                }
                continue;
            }
            if (($spec['type'] ?? null) === 'integer') {
                $validated = filter_var($value, FILTER_VALIDATE_INT);
                if ($validated === false) {
                    throw new \RuntimeException(sprintf('Параметр "%s" должен быть целым числом.', $name));
                }
                if (isset($spec['min']) && $validated < $spec['min'] || isset($spec['max']) && $validated > $spec['max']) {
                    throw new \RuntimeException(sprintf('Параметр "%s" находится вне допустимого диапазона.', $name));
                }
                $value = $validated;
            } elseif (($spec['type'] ?? null) === 'list') {
                $allowed = is_array($spec['items'] ?? null) ? array_column($spec['items'], 'value') : [];
                if ($allowed !== [] && !in_array($value, array_map('strval', $allowed), true)) {
                    throw new \RuntimeException(sprintf('Недопустимое значение параметра "%s". Допустимо: %s.', $name, implode(', ', $allowed)));
                }
            }
            $values[$name] = $value;
        }
        if ($positional !== []) {
            throw new \RuntimeException('Переданы лишние позиционные аргументы.');
        }

        return $values;
    }

    /** @param array<string, mixed> $task */
    private function workingDirectory(array $task, mixed $project): string
    {
        if (($task['context'] ?? null) !== 'project') {
            return '/tmp/.docker-cli';
        }
        if (!is_string($project) || $project === '') {
            throw new \RuntimeException('Для задачи с context: project необходимо указать --project.');
        }
        $registry = $this->registry ?? new ProjectRegistry();
        if (!$registry->hasProject($project)) {
            throw new \RuntimeException(sprintf('Проект "%s" не зарегистрирован.', $project));
        }
        $root = $registry->readProjectConfig($project)['data']['project']['document_root'] ?? null;
        if (!is_string($root) || !is_dir($root)) {
            throw new \RuntimeException(sprintf('Document root проекта "%s" не существует.', $project));
        }

        return $root;
    }

    /** @param array<string, mixed> $task @param array<string, string|int> $values */
    private function compileScript(array $task, array $values): string
    {
        $action = preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_-]*)\s*\}\}/', function (array $match) use ($values): string {
            if (!array_key_exists($match[1], $values)) {
                throw new \RuntimeException(sprintf('В action используется не переданный параметр "%s".', $match[1]));
            }

            return escapeshellarg((string) $values[$match[1]]);
        }, $task['action']);

        return sprintf("#!/usr/bin/env bash\n# Задача: %s (%s)\n# Параметры: %s\nset -Eeuo pipefail\n%s\n", str_replace("\n", ' ', $task['name']), $task['code'], implode(', ', array_keys($task['parameters'] ?? [])), $action);
    }

    private function normalizeName(string $name): string
    {
        return str_replace('-', '_', $name);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Не удалось создать директорию "%s".', $directory));
        }
    }
}
