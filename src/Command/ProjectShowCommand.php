<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ProjectShowCommand extends Command
{
    public function __construct(private readonly ?ProjectRegistry $registry = null)
    {
        parent::__construct('project:show');
        $this->setDescription('Вывести YAML-конфигурацию текущего или указанного проекта.');
        $this->addArgument('project', InputArgument::OPTIONAL, 'Кодовое имя зарегистрированного проекта.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->registry ?? new ProjectRegistry();
        $project = $input->getArgument('project');
        $projectName = is_string($project) && $project !== ''
            ? $project
            : $registry->projectNameFromContext();

        if ($projectName === null) {
            $output->writeln('<error>Запустите команду в директории зарегистрированного проекта или укажите проект.</error>');

            return Command::FAILURE;
        }

        if (!$registry->hasProject($projectName)) {
            $output->writeln(sprintf('<error>Проект "%s" не зарегистрирован.</error>', $projectName));

            return Command::FAILURE;
        }

        $contents = file_get_contents($registry->projectConfigFile($projectName));
        if ($contents === false) {
            $output->writeln(sprintf('<error>Не удалось прочитать конфигурацию проекта "%s".</error>', $projectName));

            return Command::FAILURE;
        }

        $output->write($contents);

        return Command::SUCCESS;
    }
}
