<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Project\DataInitializer;
use DockerCli\Project\ProjectRegistry;
use DockerCli\Project\ZipArchiveManager;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DataDumpCommand extends AbstractCommand
{
    private const DBMS = ['mysql', 'postgres'];

    public function __construct(
        private readonly ?ProjectRegistry $registry = null,
        private readonly ?DataInitializer $initializer = null,
    ) {
        parent::__construct('data:dump');
        $this->setDescription('Снять дамп БД проекта.');
        $this->addOption('dbms', null, InputOption::VALUE_REQUIRED, 'СУБД проекта: mysql или postgres.');
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Код зарегистрированного проекта.');
        $this->addOption('compress', null, InputOption::VALUE_REQUIRED, 'Формат архива: zip.');
        $this->addArgument('path', InputArgument::REQUIRED, 'Путь к файлу дампа.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dbms = $input->getOption('dbms');
        if (!is_string($dbms) || !in_array($dbms, self::DBMS, true)) {
            $this->writeMessage($output, '<error>Укажите поддерживаемую СУБД через --dbms: mysql или postgres.</error>');
            return Command::FAILURE;
        }

        $registry = $this->registry ?? new ProjectRegistry();
        $projectName = $this->resolveProjectName($input, $registry);
        if ($projectName === null) {
            $this->writeMessage($output, '<error>Укажите код зарегистрированного проекта через --project или запустите команду в директории зарегистрированного проекта.</error>');
            return Command::FAILURE;
        }

        if (!$registry->hasProject($projectName)) {
            $this->writeMessage($output, sprintf('<error>Проект "%s" не зарегистрирован.</error>', $projectName));
            return Command::FAILURE;
        }

        $path = $input->getArgument('path');
        if (!is_string($path) || $path === '') {
            $this->writeMessage($output, '<error>Укажите путь к файлу дампа.</error>');
            return Command::FAILURE;
        }

        $compress = $input->getOption('compress');
        if ($compress !== null && $compress !== 'zip') {
            $this->writeMessage($output, '<error>Поддерживаемый формат сжатия: zip.</error>');
            return Command::FAILURE;
        }

        $config = $registry->readProjectConfig($projectName);
        $database = $config['data']['databases'][$dbms]['database'] ?? $projectName;
        if (!is_string($database) || $database === '') {
            $this->writeMessage($output, sprintf('<error>В конфигурации проекта "%s" не задана БД для %s.</error>', $projectName, $dbms));
            return Command::FAILURE;
        }

        try {
            $code = ($this->initializer ?? new DataInitializer())->dump($dbms, $database, $path, $output);
        } catch (MissingConfigException $exception) {
            $this->writeMessage($output, sprintf('<error>Системная конфигурация не инициализирована. Отсутствуют файлы: %s.</error>', implode(', ', $exception->missingFiles())));
            return Command::FAILURE;
        }

        if ($code === Command::SUCCESS && $compress === 'zip') {
            try {
                $path = (new ZipArchiveManager())->compress($path);
            } catch (RuntimeException $exception) {
                $this->writeMessage($output, sprintf('<error>%s</error>', $exception->getMessage()));
                return Command::FAILURE;
            }
        }

        if ($code === Command::SUCCESS) {
            $this->writeMessage($output, sprintf('<info>Дамп БД "%s" проекта "%s" записан в "%s".</info>', $database, $projectName, $path));
        }

        return $code;
    }

    private function resolveProjectName(InputInterface $input, ProjectRegistry $registry): ?string
    {
        $projectName = $input->getOption('project');
        if (is_string($projectName) && $projectName !== '') {
            return $projectName;
        }

        return $registry->projectNameFromContext();
    }
}
