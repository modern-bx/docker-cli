<?php

declare(strict_types=1);

namespace DockerCli\Queue;

use DockerCli\Task\TaskRepository;

final class QueueItemValidator
{
    public function __construct(private readonly TaskRepository $commands)
    {
    }

    /** @param mixed $item @return list<string> */
    public function validate(mixed $item): array
    {
        $errors = [];
        if (!is_array($item)) {
            return ['Корень YAML должен быть объектом.'];
        }
        if (($item['meta']['schema'] ?? null) !== 'queue-item') {
            $errors[] = 'meta.schema должен быть равен queue-item.';
        }
        if ((string) ($item['meta']['version'] ?? '') !== '0.1') {
            $errors[] = 'meta.version должен быть равен 0.1.';
        }
        $commands = $item['task']['commands'] ?? null;
        if (!is_array($commands) || !array_is_list($commands) || $commands === []) {
            $errors[] = 'task.commands должен быть непустым списком.';

            return $errors;
        }
        foreach ($commands as $index => $command) {
            $prefix = sprintf('task.commands.%d', $index);
            if (!is_array($command) || !is_string($command['code'] ?? null) || $command['code'] === '') {
                $errors[] = $prefix . '.code должен быть непустой строкой.';
                continue;
            }
            if (isset($command['project']) && !is_string($command['project'])) {
                $errors[] = $prefix . '.project должен быть строкой.';
            }
            if (isset($command['arguments']) && !is_array($command['arguments'])) {
                $errors[] = $prefix . '.arguments должен быть объектом.';
                continue;
            }
            try {
                $spec = $this->commands->find($command['code'])['task'];
            } catch (\Throwable $exception) {
                $errors[] = $prefix . ': ' . $exception->getMessage();
                continue;
            }
            $arguments = $command['arguments'] ?? [];
            foreach ($arguments as $name => $argument) {
                if (!array_key_exists($name, $spec['parameters'] ?? [])) {
                    $errors[] = sprintf('%s.arguments.%s не описан в команде.', $prefix, $name);
                }
                if (!is_array($argument) || !array_key_exists('value', $argument)) {
                    $errors[] = sprintf('%s.arguments.%s должен содержать value.', $prefix, $name);
                    continue;
                }
                $type = $spec['parameters'][$name]['type'] ?? null;
                $value = $argument['value'];
                if ($type === 'string' && !is_string($value)) {
                    $errors[] = sprintf('%s.arguments.%s.value должен быть строкой.', $prefix, $name);
                } elseif ($type === 'integer' && !is_int($value)) {
                    $errors[] = sprintf('%s.arguments.%s.value должен быть целым числом.', $prefix, $name);
                } elseif ($type === 'list' && !is_scalar($value)) {
                    $errors[] = sprintf('%s.arguments.%s.value должен быть скалярным значением списка.', $prefix, $name);
                }
            }
            foreach ($spec['parameters'] ?? [] as $name => $parameter) {
                if (($parameter['required'] ?? false) === true && !array_key_exists($name, $arguments)) {
                    $errors[] = sprintf('%s.arguments.%s является обязательным.', $prefix, $name);
                }
            }
            if (($spec['context'] ?? null) === 'project' && (!isset($command['project']) || $command['project'] === '')) {
                $errors[] = $prefix . '.project обязателен для команды с context: project.';
            }
        }

        return $errors;
    }
}
