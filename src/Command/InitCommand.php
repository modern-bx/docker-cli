<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\SystemCompose;
use DockerCli\Service\TranslatorFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InitCommand extends Command
{
    private TranslatorInterface $translator;

    public function __construct(?TranslatorInterface $translator = null)
    {
        $this->translator = $translator ?? TranslatorFactory::create();
        parent::__construct('init');
        $this->setDescription($this->translator->trans('command.init.description'));
        $this->addOption('update', null, InputOption::VALUE_NONE, $this->translator->trans('command.init.update_option'));
        $this->addOption('migrate', null, InputOption::VALUE_NONE, $this->translator->trans('command.init.migrate_option'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $update = (bool) $input->getOption('update');
        $migrate = (bool) $input->getOption('migrate');

        if ($update) {
            $question = new ConfirmationQuestion($this->translator->trans('command.init.update_confirm') . ' ', false);
            if (!$this->getHelper('question')->ask($input, $output, $question)) {
                $output->writeln('<comment>' . $this->translator->trans('command.init.cancelled') . '</comment>');

                return Command::SUCCESS;
            }
        }

        $compose = new SystemCompose();
        $created = $compose->init($update, $migrate);
        $message = $created ? 'config.created' : 'config.exists';

        $output->writeln('<info>' . $this->translator->trans($message, [
            '%directory%' => $compose->directory(),
        ]) . '</info>');

        return Command::SUCCESS;
    }
}
