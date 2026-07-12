<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Framework\Description\FrameworkDescriptionService;
use DockerCli\Framework\FrameworkDetectionService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

final class RegisterCommand extends Command
{
    public function __construct(
        private readonly ?FrameworkDetectionService $detectionService = null,
        private readonly ?FrameworkDescriptionService $descriptionService = null,
    ) {
        parent::__construct('register');
        $this->setDescription('Зарегистрировать проект docker-cli.');
        $this->addArgument('project-name', InputArgument::REQUIRED, 'Имя проекта.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $framework = ($this->detectionService ?? FrameworkDetectionService::createDefault())->detect();
        if ($framework === null) {
            $output->writeln('<error>Не удалось определить фреймворк проекта.</error>');

            return Command::FAILURE;
        }

        $projectName = (string) $input->getArgument('project-name');
        $projectsDirectory = $this->projectsDirectory();
        $projectDirectory = $projectsDirectory . DIRECTORY_SEPARATOR . $projectName;

        if (is_dir($projectDirectory)) {
            $output->writeln(sprintf('<error>Проект "%s" уже зарегистрирован.</error>', $projectName));

            return Command::FAILURE;
        }

        if (!is_dir($projectsDirectory) && !mkdir($projectsDirectory, 0775, true) && !is_dir($projectsDirectory)) {
            $output->writeln(sprintf('<error>Не удалось создать директорию "%s".</error>', $projectsDirectory));

            return Command::FAILURE;
        }

        if (!mkdir($projectDirectory, 0775) && !is_dir($projectDirectory)) {
            $output->writeln(sprintf('<error>Не удалось создать директорию проекта "%s".</error>', $projectDirectory));

            return Command::FAILURE;
        }

        $description = ($this->descriptionService ?? new FrameworkDescriptionService())->describe($framework);
        $projectRoot = $framework->getProjectRoot();

        $this->writeYaml($projectDirectory . DIRECTORY_SEPARATOR . 'project.yaml', [
            'meta' => [
                'schema' => 'project',
                'version' => 0.1,
            ],
            'data' => [
                'project' => [
                    'name' => $projectName,
                    'framework' => $description->getCodeName()->value,
                    'root' => $projectRoot,
                ],
            ],
        ]);

        $this->writeYaml($projectRoot . DIRECTORY_SEPARATOR . '.docker-cli.yaml', [
            'meta' => [
                'schema' => 'project-meta',
                'version' => 0.1,
            ],
            'data' => [
                'project' => [
                    'name' => $projectName,
                ],
            ],
        ]);

        $output->writeln(sprintf('<info>Проект "%s" зарегистрирован.</info>', $projectName));

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

    /** @param array<string, mixed> $data */
    private function writeYaml(string $file, array $data): void
    {
        file_put_contents($file, Yaml::dump($data, 4, 2));
    }
}
