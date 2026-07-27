<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Task\TaskRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class TaskListCommand extends Command
{
    public function __construct(private readonly ?TaskRepository $repository = null)
    {
        parent::__construct('task:list');
        $this->setDescription('Вывести список пользовательских задач.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $definitions = ($this->repository ?? new TaskRepository())->all();
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
                $this->signature($task),
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
    private function signature(array $task): string
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

        return $details === [] ? $signature : $signature . "\n\n" . implode("\n\n", $details);
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
        if (isset($spec['description']) && is_string($spec['description'])) {
            $lines[] = "  Описание:\n" . $this->indent($spec['description'], 4);
        }

        return implode("\n", $lines);
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
