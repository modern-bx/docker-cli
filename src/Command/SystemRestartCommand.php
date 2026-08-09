<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\SystemCompose;
use DockerCli\Service\TranslatorFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SystemRestartCommand extends AbstractCommand
{
    use DockerComposeRunner;

    private TranslatorInterface $translator;

    public function __construct(?TranslatorInterface $translator = null)
    {
        $this->translator = $translator ?? TranslatorFactory::create();
        parent::__construct('system:restart');
        $this->setAliases(['restart']);
        $this->setDescription($this->translator->trans('command.restart.description'));
        $this->addOption('service', null, InputOption::VALUE_REQUIRED, 'Сервисы для перезапуска, разделённые запятыми.');
        $this->addOption('no-rebuild-images', null, InputOption::VALUE_NONE, 'Не собирать образы при повторном запуске системы.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $compose = new SystemCompose();
        $serviceOption = $input->getOption('service');
        if (is_string($serviceOption) && trim($serviceOption) !== '') {
            $services = array_values(array_unique(array_filter(array_map('trim', explode(',', $serviceOption)))));
            foreach ($services as $service) {
                if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $service) !== 1) {
                    $this->writeMessage($output, sprintf('<error>Некорректное имя сервиса "%s".</error>', $service));
                    return Command::INVALID;
                }
            }

            return $this->runOperation($compose, 'restart', $services, $output, $this->translator);
        }
        $stopCode = $this->runOperation($compose, 'down', ['--remove-orphans'], $output, $this->translator);
        if ($stopCode !== Command::SUCCESS) {
            return $stopCode;
        }

        $arguments = ['-d'];
        if ($input->getOption('no-rebuild-images')) $arguments[] = '--no-build';

        return $this->runOperation($compose, 'up', $arguments, $output, $this->translator);
    }
}
