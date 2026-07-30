<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ProjectConfigSetCommand extends AbstractCommand
{
    public function __construct(private readonly ?ProjectRegistry $registry = null)
    {
        parent::__construct('project:config-set');
        $this->setDescription('Записать значение в конфигурацию текущего проекта.');
        $this->addArgument('path', InputArgument::REQUIRED, 'Путь внутри data проекта, например databases.mysql.password.');
        $this->addArgument('value', InputArgument::REQUIRED, 'Новое значение.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->registry ?? new ProjectRegistry();
        $projectName = $registry->projectNameFromContext();
        if ($projectName === null || !$registry->hasProject($projectName)) {
            $this->writeMessage($output, '<error>Запустите команду в директории зарегистрированного проекта.</error>');
            return Command::FAILURE;
        }

        $path = $input->getArgument('path');
        if (!is_string($path) || !$this->isValidPath($path)) {
            $this->writeMessage($output, '<error>Путь должен содержать непустые сегменты, разделенные точками.</error>');
            return Command::FAILURE;
        }

        $value = $input->getArgument('value');
        if (!is_string($value)) {
            $this->writeMessage($output, '<error>Значение должно быть строкой.</error>');
            return Command::FAILURE;
        }

        $config = $registry->readProjectConfig($projectName);
        if (!isset($config['data']) || !is_array($config['data'])) {
            $config['data'] = [];
        }

        $this->setPath($config['data'], explode('.', $path), $value);
        $registry->writeProjectConfig($projectName, $config);
        $this->writeMessage($output, sprintf('<info>Значение "%s" записано в конфигурацию проекта "%s".</info>', $path, $projectName));

        return Command::SUCCESS;
    }

    private function isValidPath(string $path): bool
    {
        return $path !== '' && !str_starts_with($path, '.') && !str_ends_with($path, '.') && !str_contains($path, '..');
    }

    /** @param list<string> $segments */
    private function setPath(array &$data, array $segments, string $value): void
    {
        $current =& $data;
        foreach ($segments as $index => $segment) {
            if ($index === array_key_last($segments)) {
                $current[$segment] = $value;
                return;
            }

            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }
            $current =& $current[$segment];
        }
    }
}
