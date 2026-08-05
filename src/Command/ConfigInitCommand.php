<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\SystemCompose;
use DockerCli\Project\OpenRestyHostRenderer;
use DockerCli\Project\OfeliaConfigRenderer;
use DockerCli\Project\OfeliaReloadScheduler;
use DockerCli\Project\XdebugPortManager;
use DockerCli\Service\TranslatorFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ConfigInitCommand extends AbstractCommand
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
        $this->addOption('force', null, InputOption::VALUE_NONE, $this->translator->trans('command.init.force_option'));
        $this->addOption('examples', null, InputOption::VALUE_NONE, $this->translator->trans('command.init.examples_option'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $update = (bool) $input->getOption('update');
        $migrate = (bool) $input->getOption('migrate');
        $rebuild = (bool) $input->getOption('rebuild');
        $force = (bool) $input->getOption('force');
        $examples = (bool) $input->getOption('examples');

        if ($update && !$force) {
            $question = new ConfirmationQuestion($this->translator->trans('command.init.update_confirm') . ' ', false);
            if (!$this->getHelper('question')->ask($input, $output, $question)) {
                $this->writeMessage($output, '<comment>' . $this->translator->trans('command.init.cancelled') . '</comment>');

                return Command::SUCCESS;
            }
        }

        $compose = new SystemCompose();
        $created = $compose->init($update, $migrate, $examples);
        (new OfeliaConfigRenderer())->render();
        if ($this->ensureBitrixWizardPassword($compose)) {
            $created = true;
        }
        $message = $created ? 'config.created' : 'config.exists';

        $this->writeMessage($output, '<info>' . $this->translator->trans($message, [
            '%directory%' => $compose->directory(),
        ]) . '</info>');

        if ($rebuild) {
            (new XdebugPortManager())->rebuildProjectPorts($this->projectsDirectory());
            (new OpenRestyHostRenderer())->render();
            (new OfeliaReloadScheduler())->enqueue();
            $this->writeMessage($output, '<info>' . $this->translator->trans('config.rebuilt') . '</info>');
        }

        return Command::SUCCESS;
    }

    private function projectsDirectory(): string
    {
        $home = getenv('HOME') ?: throw new \RuntimeException('HOME environment variable is not set.');

        return $home . DIRECTORY_SEPARATOR . '.config' . DIRECTORY_SEPARATOR . 'docker-cli' . DIRECTORY_SEPARATOR . 'state' . DIRECTORY_SEPARATOR . 'projects';
    }

    private function ensureBitrixWizardPassword(SystemCompose $compose): bool
    {
        $file = $compose->playwrightDataDirectory() . DIRECTORY_SEPARATOR . 'bitrix' . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'wizard.yaml';
        if (!is_file($file)) {
            return false;
        }

        $data = Yaml::parseFile($file);
        if (!is_array($data) || !is_array($data['admin'] ?? null) || ($data['admin']['password'] ?? '') !== '') {
            return false;
        }

        $data['admin']['password'] = $this->randomPassword();
        file_put_contents($file, Yaml::dump($data, 4, 2));

        return true;
    }

    private function randomPassword(): string
    {
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $digits = '0123456789';
        $alphabet = $lowercase . $uppercase . $digits;
        $characters = [
            $lowercase[random_int(0, strlen($lowercase) - 1)],
            $uppercase[random_int(0, strlen($uppercase) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
        ];

        while (count($characters) < 24) {
            $characters[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        for ($index = count($characters) - 1; $index > 0; --$index) {
            $swapIndex = random_int(0, $index);
            [$characters[$index], $characters[$swapIndex]] = [$characters[$swapIndex], $characters[$index]];
        }

        return implode('', $characters);
    }
}
