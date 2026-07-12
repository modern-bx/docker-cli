<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\SystemCompose;
use DockerCli\Service\TranslatorFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InitCommand extends Command
{
    private TranslatorInterface $translator;

    public function __construct(?TranslatorInterface $translator = null)
    {
        $this->translator = $translator ?? TranslatorFactory::create();
        parent::__construct('init');
        $this->setDescription($this->translator->trans('command.init.description'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $compose = new SystemCompose();
        $created = $compose->init();
        $message = $created ? 'config.created' : 'config.exists';

        $output->writeln('<info>' . $this->translator->trans($message, [
            '%directory%' => $compose->directory(),
        ]) . '</info>');

        return Command::SUCCESS;
    }
}
