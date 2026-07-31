<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\SystemCompose;
use DockerCli\Project\ProjectRegistry;
use DockerCli\Service\TranslatorFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractShellCommand extends AbstractCommand
{
    use DockerComposeRunner;

    public function __construct(string $name, private readonly ?ProjectRegistry $registry = null)
    {
        parent::__construct($name);
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Кодовое имя зарегистрированного проекта.');
    }

    /** @param list<string> $command */
    protected function runInPhpFpm(InputInterface $input, OutputInterface $output, array $command): int
    {
        $workingDirectory = $this->workingDirectory($input, $output);
        if ($workingDirectory === null) {
            return self::FAILURE;
        }

        return $this->runOperation(new SystemCompose(), 'exec', [
            '--user', 'docker-cli',
            '--workdir', $workingDirectory,
            '--env', 'HOME=/home/docker-cli',
            '--env', 'USER=docker-cli',
            '--env', 'LOGNAME=docker-cli',
            '--env', 'SHELL=/bin/bash',
            '--env', 'BASH_ENV=/dev/null',
            'php-fpm-8.2',
            ...$command,
        ], $output, TranslatorFactory::create());
    }

    private function workingDirectory(InputInterface $input, OutputInterface $output): ?string
    {
        $registry = $this->registry ?? new ProjectRegistry();
        $projectOption = $input->getOption('project');
        $projectName = is_string($projectOption) && $projectOption !== ''
            ? $projectOption
            : $registry->projectNameFromContext();
        if ($projectName === null) {
            return '/home/docker-cli';
        }
        if (!$registry->hasProject($projectName)) {
            $this->writeMessage($output, sprintf('<error>Проект "%s" не зарегистрирован.</error>', $projectName));

            return null;
        }

        $projectRoot = $registry->readProjectConfig($projectName)['data']['project']['root'] ?? null;
        if (!is_string($projectRoot) || $projectRoot === '') {
            $this->writeMessage($output, sprintf('<error>В конфигурации проекта "%s" не указан корень.</error>', $projectName));

            return null;
        }

        return $this->containerPath($projectRoot);
    }

    private function containerPath(string $hostPath): string
    {
        $hostPath = realpath($hostPath) ?: $hostPath;
        $home = getenv('HOME');
        if (is_string($home) && ($hostPath === $home || str_starts_with($hostPath, rtrim($home, '/') . '/'))) {
            return $hostPath;
        }

        return '/host' . $hostPath;
    }
}
