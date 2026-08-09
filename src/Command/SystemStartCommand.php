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

final class SystemStartCommand extends AbstractCommand
{
    use DockerComposeRunner;

    private TranslatorInterface $translator;

    public function __construct(?TranslatorInterface $translator = null)
    {
        $this->translator = $translator ?? TranslatorFactory::create();
        parent::__construct('system:start');
        $this->setAliases(['start']);
        $this->setDescription($this->translator->trans('command.start.description'));
        $this->addOption('no-rebuild-images', null, InputOption::VALUE_NONE, 'Не собирать образы, даже если Dockerfile или Compose-конфигурация изменились.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $arguments = ['-d'];
        if ($input->getOption('no-rebuild-images')) $arguments[] = '--no-build';

        return $this->runOperation(new SystemCompose(), 'up', $arguments, $output, $this->translator);
    }
}
