<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Framework\Description\FrameworkDescriptionService;
use DockerCli\Framework\FrameworkDetectionService;
use DockerCli\Project\ConfigurableServicesRestarter;
use DockerCli\Project\OpenRestyHostRenderer;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use function DockerCli\Util\join_path;

final class UpCommand extends Command
{
    public function __construct(
        private readonly ?FrameworkDetectionService $detectionService = null,
        private readonly ?FrameworkDescriptionService $descriptionService = null,
    ) {
        parent::__construct('up');
        $this->setDescription('Зарегистрировать проект docker-cli.');
        $this->addArgument('project-name', InputArgument::OPTIONAL, 'Имя проекта. По умолчанию используется имя папки проекта.');
        $this->addOption('no-restart', null, InputOption::VALUE_NONE, 'Не перезапускать общие проектные сервисы.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $framework = ($this->detectionService ?? FrameworkDetectionService::createDefault())->detect();
        if ($framework === null) {
            $output->writeln('<error>Не удалось определить фреймворк проекта.</error>');

            return Command::FAILURE;
        }

        $projectName = $this->resolveProjectName($input, $framework->getProjectRoot());
        if (!$this->isValidProjectName($projectName)) {
            $output->writeln(sprintf('<error>Имя проекта "%s" не соответствует конвенции: используйте строчные латинские буквы, цифры и дефисы; имя должно начинаться и заканчиваться буквой или цифрой.</error>', $projectName));

            return Command::FAILURE;
        }

        $projectsDirectory = $this->projectsDirectory();
        $projectDirectory = join_path($projectsDirectory, $projectName);

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

        $this->writeYaml(join_path($projectDirectory, 'project.yaml'), [
            'meta' => [
                'schema' => 'project',
                'version' => 0.1,
            ],
            'data' => [
                'project' => [
                    'name' => $projectName,
                    'framework' => $description->getCodeName()->value,
                    'language' => 'php',
                    ...$this->languageVersionConfig($projectRoot),
                    'root' => $projectRoot,
                    'document_root' => $framework->getDocumentRoot(),
                ],
            ],
        ]);

        $this->writeYaml(join_path($projectRoot, '.docker-cli.yaml'), [
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

        (new OpenRestyHostRenderer())->render();

        if (!$input->getOption('no-restart')) {
            $restartCode = (new ConfigurableServicesRestarter())->restart($output);
            if ($restartCode !== Command::SUCCESS) {
                return $restartCode;
            }
        }

        $output->writeln(sprintf('<info>Проект "%s" зарегистрирован.</info>', $projectName));

        return Command::SUCCESS;
    }

    /** @return array{version?: string} */
    private function languageVersionConfig(string $projectRoot): array
    {
        $version = $this->detectPhpVersion($projectRoot);

        return $version === null ? [] : ['version' => $version];
    }

    private function detectPhpVersion(string $projectRoot): ?string
    {
        $composerJson = join_path($projectRoot, 'composer.json');
        if (!is_file($composerJson)) {
            return null;
        }

        $contents = file_get_contents($composerJson);
        if ($contents === false) {
            return null;
        }

        $composer = json_decode($contents, true);
        if (!is_array($composer)) {
            return null;
        }

        $constraint = $composer['require']['php'] ?? null;
        if (!is_string($constraint)) {
            return null;
        }

        if (preg_match('/(?<!\d)([78]\.\d+)(?!\d)/', $constraint, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function resolveProjectName(InputInterface $input, string $projectRoot): string
    {
        $projectName = $input->getArgument('project-name');
        if (is_string($projectName) && $projectName !== '') {
            return $projectName;
        }

        return basename($projectRoot);
    }

    private function isValidProjectName(string $projectName): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $projectName);
    }

    private function projectsDirectory(): string
    {
        $home = getenv('HOME') ?: null;
        if ($home === null) {
            throw new \RuntimeException('Unable to determine HOME directory.');
        }

        return join_path($home, '.config', 'docker-cli', 'projects');
    }

    /** @param array<string, mixed> $data */
    private function writeYaml(string $file, array $data): void
    {
        file_put_contents($file, Yaml::dump($data, 4, 2));
    }
}
