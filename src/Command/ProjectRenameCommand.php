<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use function DockerCli\Util\join_path;

final class ProjectRenameCommand extends Command
{
    public function __construct(private readonly ?ProjectRegistry $registry = null, private readonly ?CommandContext $context = null)
    {
        parent::__construct('project:rename');
        $this->setDescription('Переименовать зарегистрированный проект.');
        $this->addArgument('code', InputArgument::REQUIRED, 'Новый код проекта.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $code = (string) $input->getArgument('code');
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $code) !== 1) {
            $output->writeln('<error>Код проекта должен содержать строчные латинские буквы, цифры и дефисы.</error>');
            return Command::FAILURE;
        }

        $registry = $this->registry ?? new ProjectRegistry();
        $oldCode = $registry->projectNameFromContext();
        if ($oldCode === null || !$registry->hasProject($oldCode)) {
            $output->writeln('<error>Запустите команду в директории зарегистрированного проекта.</error>');
            return Command::FAILURE;
        }
        if ($oldCode === $code) {
            $output->writeln('<info>Код проекта не изменился.</info>');
            return Command::SUCCESS;
        }
        if ($registry->hasProject($code) || file_exists($registry->projectDirectory($code))) {
            $output->writeln(sprintf('<error>Проект "%s" уже зарегистрирован.</error>', $code));
            return Command::FAILURE;
        }

        $config = $registry->readProjectConfig($oldCode);
        $project = $config['data']['project'] ?? null;
        $root = is_array($project) ? ($project['root'] ?? null) : null;
        $localFile = is_string($root) ? join_path($root, '.docker-cli', 'project.yaml') : '';
        if (!is_array($project) || !is_string($root) || $root === '' || !is_file($localFile)) {
            $output->writeln('<error>Конфигурация проекта повреждена.</error>');
            return Command::FAILURE;
        }
        $localConfig = Yaml::parseFile($localFile);
        if (!is_array($localConfig) || !is_array($localConfig['data']['project'] ?? null)) {
            $output->writeln('<error>Локальная конфигурация проекта повреждена.</error>');
            return Command::FAILURE;
        }

        $config['data']['project']['name'] = $code;
        $localConfig['data']['project']['name'] = $code;
        $oldDirectory = $registry->projectDirectory($oldCode);
        $newDirectory = $registry->projectDirectory($code);
        $registry->writeProjectConfig($oldCode, $config);
        file_put_contents($localFile, Yaml::dump($localConfig, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        if (!rename($oldDirectory, $newDirectory)) {
            $config['data']['project']['name'] = $oldCode;
            $localConfig['data']['project']['name'] = $oldCode;
            $registry->writeProjectConfig($oldCode, $config);
            file_put_contents($localFile, Yaml::dump($localConfig, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
            $output->writeln('<error>Не удалось переименовать директорию проекта в реестре.</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Проект "%s" переименован в "%s".</info>', $oldCode, $code));
        ($this->context ?? CommandContext::fromEnvironment())->addNotification('project.rename', 'command', 'info', sprintf('Проект **%s** переименован в **%s**.', $oldCode, $code));
        return Command::SUCCESS;
    }
}
