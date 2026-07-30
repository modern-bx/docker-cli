<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

final class ProjectConfigGetCommand extends AbstractCommand
{
    public function __construct(private readonly ?ProjectRegistry $registry = null)
    {
        parent::__construct('project:config-get');
        $this->setDescription('Вывести значение из конфигурации текущего проекта.');
        $this->addArgument('path', InputArgument::REQUIRED, 'Путь внутри data проекта, например databases.mysql.password.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->registry ?? new ProjectRegistry();
        $projectName = $registry->projectNameFromContext();
        if ($projectName === null || !$registry->hasProject($projectName)) {
            $output->writeln('<error>Запустите команду в директории зарегистрированного проекта.</error>');
            return Command::FAILURE;
        }

        $path = $input->getArgument('path');
        if (!is_string($path) || !$this->isValidPath($path)) {
            $output->writeln('<error>Путь должен содержать непустые сегменты, разделенные точками.</error>');
            return Command::FAILURE;
        }

        $config = $registry->readProjectConfig($projectName);
        $found = false;
        $value = $this->getPath($config['data'] ?? [], explode('.', $path), $found);
        if (!$found) {
            $output->writeln(sprintf('<error>Путь "%s" не найден в конфигурации проекта.</error>', $path));
            return Command::FAILURE;
        }

        if (is_array($value)) {
            $output->write(Yaml::dump($value, 6, 2));
            return Command::SUCCESS;
        }

        if (is_bool($value)) {
            $output->writeln($value ? 'true' : 'false');
            return Command::SUCCESS;
        }

        if ($value === null) {
            $output->writeln('null');
            return Command::SUCCESS;
        }

        $output->writeln((string) $value);

        return Command::SUCCESS;
    }

    private function isValidPath(string $path): bool
    {
        return $path !== '' && !str_starts_with($path, '.') && !str_ends_with($path, '.') && !str_contains($path, '..');
    }

    /** @param list<string> $segments */
    private function getPath(mixed $data, array $segments, bool &$found): mixed
    {
        $current = $data;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                $found = false;
                return null;
            }
            $current = $current[$segment];
        }

        $found = true;

        return $current;
    }
}
