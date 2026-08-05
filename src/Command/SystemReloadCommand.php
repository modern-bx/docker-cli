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

final class SystemReloadCommand extends AbstractCommand
{
    private const RELOAD_COMMANDS = [
        'openresty' => ['openresty', '-s', 'reload'],
        'panel-gateway' => ['nginx', '-s', 'reload'],
    ];

    private TranslatorInterface $translator;

    public function __construct(?TranslatorInterface $translator = null)
    {
        $this->translator = $translator ?? TranslatorFactory::create();
        parent::__construct('system:reload');
        $this->setDescription('Перезагрузить конфигурацию системных сервисов без перезапуска контейнеров.');
        $this->addOption('service', null, InputOption::VALUE_REQUIRED, 'Сервисы для reload, разделённые запятыми. По умолчанию: все поддерживаемые.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $serviceOption = $input->getOption('service');
        $services = is_string($serviceOption) && trim($serviceOption) !== ''
            ? array_values(array_unique(array_filter(array_map('trim', explode(',', $serviceOption)))))
            : array_keys(self::RELOAD_COMMANDS);

        foreach ($services as $service) {
            if (!isset(self::RELOAD_COMMANDS[$service])) {
                $this->writeMessage($output, sprintf('<error>Сервис "%s" не поддерживает reload.</error>', $service));
                return Command::INVALID;
            }
        }

        $compose = new SystemCompose();
        foreach ($services as $service) {
            $command = array_merge($compose->dockerComposeCommand('exec'), ['--no-TTY', $service], self::RELOAD_COMMANDS[$service]);
            $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $compose->directory(), $compose->dockerProcessEnvironment());
            if (!is_resource($process)) {
                $this->writeMessage($output, sprintf('<error>Не удалось запустить reload сервиса "%s".</error>', $service));
                return Command::FAILURE;
            }
            $code = proc_close($process);
            if ($code !== Command::SUCCESS) {
                $this->writeMessage($output, sprintf('<error>Reload сервиса "%s" завершился с кодом %d.</error>', $service, $code));
                return is_int($code) ? $code : Command::FAILURE;
            }
            $this->writeMessage($output, sprintf('<info>Конфигурация сервиса "%s" перезагружена.</info>', $service));
        }

        CommandContext::fromEnvironment(new ContextUser('core.system.reload', 'task'), $output)->addMessage(
            (new Message('Конфигурация системных сервисов успешно перезагружена.'))->setNotify(true)
        );

        return Command::SUCCESS;
    }
}
