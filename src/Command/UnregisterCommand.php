<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Framework\FrameworkDetectionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

final class UnregisterCommand extends Command
{
    public function __construct(private readonly ?FrameworkDetectionService $detectionService = null)
    {
        parent::__construct('unregister');
        $this->setDescription('Удалить регистрацию проекта docker-cli.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $framework = ($this->detectionService ?? FrameworkDetectionService::createDefault())->detect();
        if ($framework === null) {
            $output->writeln('<error>Не удалось определить фреймворк проекта.</error>');

            return Command::FAILURE;
        }

        $projectRoot = $framework->getProjectRoot();
        $metaFile = $projectRoot . DIRECTORY_SEPARATOR . '.docker-cli.yaml';
        if (!is_file($metaFile)) {
            $output->writeln(sprintf('<error>Файл "%s" не найден.</error>', $metaFile));

            return Command::FAILURE;
        }

        $projectName = $this->readProjectName($metaFile);
        if ($projectName === null || $projectName === '') {
            $output->writeln(sprintf('<error>В файле "%s" не найдено имя проекта.</error>', $metaFile));

            return Command::FAILURE;
        }

        $projectDirectory = $this->projectsDirectory() . DIRECTORY_SEPARATOR . $projectName;
        if (is_dir($projectDirectory)) {
            $this->removeDirectory($projectDirectory);
        }

        unlink($metaFile);

        $output->writeln(sprintf('<info>Регистрация проекта "%s" удалена.</info>', $projectName));

        return Command::SUCCESS;
    }

    private function projectsDirectory(): string
    {
        $home = getenv('HOME') ?: null;
        if ($home === null) {
            throw new \RuntimeException('Unable to determine HOME directory.');
        }

        return rtrim($home, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.config' . DIRECTORY_SEPARATOR . 'docker-cli' . DIRECTORY_SEPARATOR . 'projects';
    }

    private function readProjectName(string $file): ?string
    {
        $data = Yaml::parseFile($file);
        if (!is_array($data)) {
            return null;
        }

        $name = $data['data']['project']['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
