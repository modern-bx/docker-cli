<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ProjectListCommand extends Command
{
    public function __construct(private readonly ?ProjectRegistry $registry = null)
    {
        parent::__construct('project:list');
        $this->setDescription('Вывести кодовые имена зарегистрированных проектов.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach (($this->registry ?? new ProjectRegistry())->registeredProjectNames() as $projectName) {
            $output->writeln($projectName);
        }

        return Command::SUCCESS;
    }
}
