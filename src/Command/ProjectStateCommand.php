<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Project\ConfigurableServicesRestarter;
use DockerCli\Project\OpenRestyHostRenderer;
use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ProjectStateCommand extends AbstractCommand
{
    public function __construct(
        string $name,
        private readonly bool $enabled,
        private readonly ?ProjectRegistry $registry = null,
    ) {
        parent::__construct($name);
        $this->addArgument('project', InputArgument::OPTIONAL, 'Кодовое имя зарегистрированного проекта.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->registry ?? new ProjectRegistry();
        $argument = $input->getArgument('project');
        $projectName = is_string($argument) && $argument !== ''
            ? $argument
            : $registry->projectNameFromContext();

        if ($projectName === null) {
            $output->writeln('<error>Запустите команду в директории зарегистрированного проекта или укажите проект.</error>');
            return Command::FAILURE;
        }
        if (!$registry->hasProject($projectName)) {
            $output->writeln(sprintf('<error>Проект "%s" не зарегистрирован.</error>', $projectName));
            return Command::FAILURE;
        }

        $config = $registry->readProjectConfig($projectName);
        if (!is_array($config['data']['project'] ?? null)) {
            $output->writeln(sprintf('<error>Конфигурация проекта "%s" повреждена.</error>', $projectName));
            return Command::FAILURE;
        }

        $config['data']['project']['enabled'] = $this->enabled;
        $registry->writeProjectConfig($projectName, $config);
        (new OpenRestyHostRenderer())->render();
        $restartCode = (new ConfigurableServicesRestarter())->restart($output);
        if ($restartCode !== Command::SUCCESS) {
            return $restartCode;
        }

        $output->writeln(sprintf(
            '<info>Проект "%s" %s.</info>',
            $projectName,
            $this->enabled ? 'включен' : 'отключен',
        ));

        return Command::SUCCESS;
    }
}
