<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\SystemCompose;
use DockerCli\Project\OpenRestyHostRenderer;
use DockerCli\Project\XdebugPortManager;
use DockerCli\Service\TranslatorFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ConfigInitCommand extends Command
{
    private TranslatorInterface $translator;

    public function __construct(?TranslatorInterface $translator = null)
    {
        $this->translator = $translator ?? TranslatorFactory::create();
        parent::__construct('config:init');
        $this->setDescription($this->translator->trans('command.init.description'));
        $this->addOption('update', null, InputOption::VALUE_NONE, $this->translator->trans('command.init.update_option'));
        $this->addOption('migrate', null, InputOption::VALUE_NONE, $this->translator->trans('command.init.migrate_option'));
        $this->addOption('rebuild', null, InputOption::VALUE_NONE, $this->translator->trans('command.init.rebuild_option'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $update = (bool) $input->getOption('update');
        $migrate = (bool) $input->getOption('migrate');
        $rebuild = (bool) $input->getOption('rebuild');

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

        if ($rebuild) {
            (new XdebugPortManager())->rebuildProjectPorts($this->projectsDirectory());
            (new OpenRestyHostRenderer())->render();
            $output->writeln('<info>' . $this->translator->trans('config.rebuilt') . '</info>');
        }

        return Command::SUCCESS;
    }

    private function projectsDirectory(): string
    {
        $home = getenv('HOME') ?: throw new \RuntimeException('HOME environment variable is not set.');

        return $home . DIRECTORY_SEPARATOR . '.config' . DIRECTORY_SEPARATOR . 'docker-cli' . DIRECTORY_SEPARATOR . 'projects';
    }
}
