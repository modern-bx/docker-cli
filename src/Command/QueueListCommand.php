<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Queue\QueueRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

final class QueueListCommand extends AbstractCommand
{
    /** @var array<string, string> */
    private const STATUS_ALIASES = [
        '10' => '10-pending', 'pending' => '10-pending', '10-pending' => '10-pending',
        '20' => '20-active', 'active' => '20-active', '20-active' => '20-active',
        '30' => '30-success', 'success' => '30-success', '30-success' => '30-success',
        '40' => '40-failure', 'failure' => '40-failure', '40-failure' => '40-failure',
        '50' => '50-error', 'error' => '50-error', '50-error' => '50-error',
    ];

    public function __construct(private readonly ?QueueRepository $queues = null)
    {
        parent::__construct('queue:list');
        $this->setDescription('Вывести список элементов очередей.');
        $this->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Код очереди. Без опции выводятся все очереди.');
        $this->addOption('status', null, InputOption::VALUE_REQUIRED, 'Статус: 10, pending, 10-pending и т. п.');
        $this->addOption('short', null, InputOption::VALUE_NONE, 'Вывести только относительные пути к элементам.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $repository = $this->queues ?? new QueueRepository();
            $queue = $input->getOption('queue');
            if ($queue !== null && (!is_string($queue) || $queue === '')) {
                throw new \InvalidArgumentException('Опция --queue должна содержать код очереди.');
            }
            $status = $this->status($input->getOption('status'));
            $items = $repository->listItems(is_string($queue) ? $queue : null, $status);
        } catch (\Throwable $exception) {
            $this->writeMessage($output, '<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($input->getOption('short')) {
            foreach ($items as $item) {
                $output->writeln($item['relativePath']);
            }
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($items as $item) {
            try {
                $document = Yaml::parseFile($item['path']);
                if (!is_array($document)) {
                    throw new \RuntimeException('Корень YAML должен быть объектом.');
                }
                $description = $this->description($document);
                $log = $this->log($document);
            } catch (\Throwable $exception) {
                $description = 'Ошибка чтения: ' . $exception->getMessage();
                $log = '—';
            }
            $rows[] = [pathinfo($item['file'], PATHINFO_FILENAME), $item['status'], $description, $log];
        }

        (new Table($output))
            ->setStyle('box-double')
            ->setHeaders(['Элемент', 'Статус', 'Описание', 'Лог'])
            ->setRows($rows)
            ->render();

        return Command::SUCCESS;
    }

    private function status(mixed $status): ?string
    {
        if ($status === null) {
            return null;
        }
        $status = is_scalar($status) ? strtolower(trim((string) $status)) : '';
        if (!isset(self::STATUS_ALIASES[$status])) {
            throw new \InvalidArgumentException(sprintf('Неизвестный статус "%s".', $status));
        }
        return self::STATUS_ALIASES[$status];
    }

    /** @param array<string, mixed> $document */
    private function description(array $document): string
    {
        $lines = [];
        foreach (($document['queue-item']['tasks'] ?? []) as $task) {
            if (!is_array($task)) {
                continue;
            }
            $parts = [(string) ($task['code'] ?? '—')];
            foreach (($task['arguments'] ?? []) as $name => $argument) {
                $value = is_array($argument) && array_key_exists('value', $argument) ? $argument['value'] : null;
                $parts[] = sprintf('%s=%s', $name, $this->value($value));
            }
            $lines[] = implode(' ', $parts);
        }
        return $lines !== [] ? implode("\n", $lines) : '—';
    }

    /** @param array<string, mixed> $document */
    private function log(array $document): string
    {
        $lines = [];
        foreach (($document['trace'] ?? []) as $timestamp => $message) {
            $lines[] = sprintf('[%s] %s', $this->time($timestamp), $this->value($message));
        }
        return $lines !== [] ? implode("\n", $lines) : '—';
    }

    private function time(mixed $timestamp): string
    {
        $timestamp = is_scalar($timestamp) ? (string) $timestamp : '';
        if (preg_match('/^(\d+)(?:\.(\d{1,6}))?$/D', $timestamp, $matches) !== 1) {
            return $timestamp !== '' ? $timestamp : '—';
        }
        $microseconds = str_pad($matches[2] ?? '', 6, '0');
        return sprintf('%s.%s UTC', gmdate('Y-m-d H:i:s', (int) $matches[1]), $microseconds);
    }

    private function value(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: get_debug_type($value);
    }
}
