<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Hook\CommandHookRunner;
use DockerCli\Project\ConfigurableServicesRestarter;
use DockerCli\Project\OpenRestyHostRenderer;
use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ProjectStateCommand extends AbstractCommand
{
    public function __construct(
        string $name,
        private readonly bool $enabled,
        private readonly ?ProjectRegistry $registry = null,
        private readonly ?CommandHookRunner $hookRunner = null,
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
            $this->writeMessage($output, '<error>Запустите команду в директории зарегистрированного проекта или укажите проект.</error>');
            return Command::FAILURE;
        }
        if (!$registry->hasProject($projectName)) {
            $this->writeMessage($output, sprintf('<error>Проект "%s" не зарегистрирован.</error>', $projectName));
            return Command::FAILURE;
        }

        $config = $registry->readProjectConfig($projectName);
        if (!is_array($config['data']['project'] ?? null)) {
            $this->writeMessage($output, sprintf('<error>Конфигурация проекта "%s" повреждена.</error>', $projectName));
            return Command::FAILURE;
        }

        $hookArguments = $input instanceof ArgvInput ? $input->getRawTokens(true) : [];
        $beforeHookCode = ($this->hookRunner ?? new CommandHookRunner())->run($this->getName() ?? '', 'before', $hookArguments);
        if ($beforeHookCode !== Command::SUCCESS) {
            return $beforeHookCode;
        }

        $config['data']['project']['enabled'] = $this->enabled;
        $registry->writeProjectConfig($projectName, $config);
        (new OpenRestyHostRenderer())->render();
        $restartCode = (new ConfigurableServicesRestarter())->restart($output);
        if ($restartCode !== Command::SUCCESS) {
            return $restartCode;
        }

        $this->writeMessage($output, sprintf(
            '<info>Проект "%s" %s.</info>',
            $projectName,
            $this->enabled ? 'включен' : 'отключен',
        ));

        return ($this->hookRunner ?? new CommandHookRunner())->run($this->getName() ?? '', 'after', $hookArguments);
    }
}
