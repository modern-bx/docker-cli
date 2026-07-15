<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\SystemCompose;
use DockerCli\Service\TranslatorFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class StartCommand extends Command
{
    use DockerComposeRunner;

    private TranslatorInterface $translator;

    public function __construct(?TranslatorInterface $translator = null)
    {
        $this->translator = $translator ?? TranslatorFactory::create();
        parent::__construct('system:start');
        $this->setDescription($this->translator->trans('command.start.description'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runOperation(new SystemCompose(), 'up', ['-d'], $output, $this->translator);
    }
}
