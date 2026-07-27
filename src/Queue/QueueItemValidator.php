<?php

declare(strict_types=1);

namespace DockerCli\Queue;

use DockerCli\Task\TaskRepository;

final class QueueItemValidator
{
    public function __construct(private readonly TaskRepository $tasks)
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
        $tasks = $item['queue-item']['tasks'] ?? null;
        if (!is_array($tasks) || !array_is_list($tasks) || $tasks === []) {
            $errors[] = 'queue-item.tasks должен быть непустым списком.';

            return $errors;
        }
        foreach ($tasks as $index => $task) {
            $prefix = sprintf('queue-item.tasks.%d', $index);
            if (!is_array($task) || !is_string($task['code'] ?? null) || $task['code'] === '') {
                $errors[] = $prefix . '.code должен быть непустой строкой.';
                continue;
            }
            if (isset($task['project']) && !is_string($task['project'])) {
                $errors[] = $prefix . '.project должен быть строкой.';
            }
            if (isset($task['arguments']) && !is_array($task['arguments'])) {
                $errors[] = $prefix . '.arguments должен быть объектом.';
                continue;
            }
            try {
                $spec = $this->tasks->find($task['code'])['task'];
            } catch (\Throwable $exception) {
                $errors[] = $prefix . ': ' . $exception->getMessage();
                continue;
            }
            $arguments = $task['arguments'] ?? [];
            foreach ($arguments as $name => $argument) {
                if (!array_key_exists($name, $spec['parameters'] ?? [])) {
                    $errors[] = sprintf('%s.arguments.%s не описан в задаче.', $prefix, $name);
                }
                if (!is_array($argument) || !array_key_exists('value', $argument)) {
                    $errors[] = sprintf('%s.arguments.%s должен содержать value.', $prefix, $name);
                    continue;
                }
                $type = $spec['parameters'][$name]['type'] ?? null;
                $value = $argument['value'];
                $strictTypes = ($spec['parameters'][$name]['strict-types'] ?? false) === true;
                if ($type === 'string' && !is_string($value) && !(!$strictTypes && is_int($value))) {
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
            if (($spec['context'] ?? null) === 'project' && (!isset($task['project']) || $task['project'] === '')) {
                $errors[] = $prefix . '.project обязателен для задачи с context: project.';
            }
        }

        return $errors;
    }
}
