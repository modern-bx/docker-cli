<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\SystemCompose;
use DockerCli\Project\ProjectRegistry;
use DockerCli\Service\TranslatorFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ShellBashCommand extends AbstractCommand
{
    use DockerComposeRunner;

    public function __construct(private readonly ?ProjectRegistry $registry = null)
    {
        parent::__construct('shell:bash');
        $this->setAliases(['bash']);
        $this->setDescription('Открыть Bash в контейнере PHP-FPM от имени пользователя docker-cli.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workingDirectory = '/home/docker-cli';
        $registry = $this->registry ?? new ProjectRegistry();
        $projectName = $registry->projectNameFromContext();
        if ($projectName !== null && $registry->hasProject($projectName)) {
            $projectRoot = $registry->readProjectConfig($projectName)['data']['project']['root'] ?? null;
            if (is_string($projectRoot) && $projectRoot !== '') {
                $workingDirectory = $this->containerPath($projectRoot);
            }
        }

        return $this->runOperation(new SystemCompose(), 'exec', [
            '--user', 'docker-cli',
            '--workdir', $workingDirectory,
            '--env', 'HOME=/home/docker-cli',
            'php-fpm-8.2',
            'bash',
        ], $output, TranslatorFactory::create());
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
