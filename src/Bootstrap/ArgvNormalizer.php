<?php

declare(strict_types=1);

namespace DockerCli\Bootstrap;

final class ArgvNormalizer
{
    /**
     * Добавляет команду по умолчанию перед разделителем аргументов внешней команды.
     *
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    public function normalize(array $arguments): array
    {
        $separator = array_search("--", $arguments, true);
        if (!is_int($separator) || $separator === 0) {
            return $arguments;
        }

        foreach (array_slice($arguments, 1, $separator - 1) as $argument) {
            if (!str_starts_with($argument, "-")) {
                return $arguments;
            }
        }

        array_splice($arguments, 1, 0, ["shell:run"]);

        return $arguments;
    }
}
