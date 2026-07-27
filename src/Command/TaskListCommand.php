<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Task\TaskRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class TaskListCommand extends Command
{
    public function __construct(private readonly ?TaskRepository $repository = null)
    {
        parent::__construct('task:list');
        $this->setDescription('Вывести список пользовательских задач.');
        $this->addOption('short', null, InputOption::VALUE_NONE, 'Вывести только краткую сигнатуру без подробностей.');
        $this->addOption('task', null, InputOption::VALUE_REQUIRED, 'Коды задач через запятую, которые нужно вывести.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $definitions = ($this->repository ?? new TaskRepository())->all();
            $definitions = $this->filterDefinitions($definitions, $input->getOption('task'));
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($definitions as $definition) {
            $task = $definition['task'];
            $rows[] = [
                (string) ($task['code'] ?? '—'),
                (string) ($task['name'] ?? '—'),
                trim((string) ($task['description'] ?? '—')),
                $this->signature($task, (bool) $input->getOption('short')),
            ];
        }

        (new Table($output))
            ->setStyle('box-double')
            ->setHeaders(['Код', 'Название', 'Описание', 'Сигнатура'])
            ->setRows($rows)
            ->render();

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $task */
    private function signature(array $task, bool $short): string
    {
        $parameters = [];
        $details = [];
        foreach (($task['parameters'] ?? []) as $name => $spec) {
            if (!is_array($spec)) {
                $parameters[] = sprintf('%s: ?', $name);
                $details[] = sprintf("%s\n  Название: —\n  Тип: ?", $name);
                continue;
            }
            $required = ($spec['required'] ?? false) === true;
            $parameters[] = sprintf('%s%s: %s', $required ? '' : '?', $name, $this->type($spec));
            $details[] = $this->parameterDetails((string) $name, $spec);
        }

        $return = is_array($task['return'] ?? null) ? $this->type($task['return']) : 'void';
        $signature = sprintf('(%s) → %s', implode(', ', $parameters), $return);

        if ($short) {
            return $signature;
        }

        $sections = [];
        if (is_array($task['tags'] ?? null) && $task['tags'] !== []) {
            $sections[] = 'Теги задачи: ' . implode(', ', array_map('strval', $task['tags']));
        }
        array_push($sections, ...$details);

        return $sections === [] ? $signature : $signature . "\n\n" . implode("\n\n", $sections);
    }

    /** @param array<string, mixed> $spec */
    private function parameterDetails(string $code, array $spec): string
    {
        $lines = [
            $code,
            '  Название: ' . $this->scalarText($spec['name'] ?? null),
            '  Тип: ' . (string) ($spec['type'] ?? '?'),
        ];

        $constraints = [];
        if (array_key_exists('required', $spec)) {
            $constraints[] = 'required: ' . (($spec['required'] ?? false) === true ? 'true' : 'false');
        }
        foreach (['min', 'max'] as $constraint) {
            if (array_key_exists($constraint, $spec)) {
                $constraints[] = sprintf('%s: %s', $constraint, $this->scalarText($spec[$constraint]));
            }
        }
        if (($spec['type'] ?? null) === 'list' && is_array($spec['items'] ?? null)) {
            $items = [];
            foreach ($spec['items'] as $item) {
                if (is_array($item)) {
                    $items[] = sprintf('%s (%s)', $this->scalarText($item['value'] ?? null), $this->scalarText($item['name'] ?? null));
                }
            }
            if ($items !== []) {
                $constraints[] = 'items: ' . implode(', ', $items);
            }
        }
        if ($constraints !== []) {
            $lines[] = '  Ограничения: ' . implode('; ', $constraints);
        }
        if (is_array($spec['tags'] ?? null) && $spec['tags'] !== []) {
            $lines[] = '  Теги: ' . implode(', ', array_map('strval', $spec['tags']));
        }
        if (isset($spec['description']) && is_string($spec['description'])) {
            $lines[] = "  Описание:\n" . $this->indent($spec['description'], 4);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{file: string, task: array<string, mixed>}> $definitions
     * @return list<array{file: string, task: array<string, mixed>}>
     */
    private function filterDefinitions(array $definitions, mixed $option): array
    {
        if (!is_string($option) || trim($option) === '') {
            return $definitions;
        }

        $codes = array_values(array_unique(array_filter(array_map('trim', explode(',', $option)), static fn (string $code): bool => $code !== '')));
        $available = array_map(static fn (array $definition): string => (string) ($definition['task']['code'] ?? ''), $definitions);
        $missing = array_values(array_diff($codes, $available));
        if ($missing !== []) {
            throw new \RuntimeException('Задачи не найдены: ' . implode(', ', $missing) . '.');
        }

        return array_values(array_filter($definitions, static fn (array $definition): bool => in_array($definition['task']['code'] ?? null, $codes, true)));
    }

    private function scalarText(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '—';
    }

    private function indent(string $text, int $spaces): string
    {
        $text = rtrim($text, "\r\n");

        return preg_replace('/^/m', str_repeat(' ', $spaces), $text) ?? $text;
    }

    /** @param array<string, mixed> $spec */
    private function type(array $spec): string
    {
        $type = (string) ($spec['type'] ?? '?');
        if ($type === 'integer' && (isset($spec['min']) || isset($spec['max']))) {
            return sprintf('integer[%s..%s]', $spec['min'] ?? '−∞', $spec['max'] ?? '+∞');
        }
        if ($type === 'list' && is_array($spec['items'] ?? null)) {
            return sprintf('list{%s}', implode('|', array_map('strval', array_column($spec['items'], 'value'))));
        }

        return $type;
    }
}
