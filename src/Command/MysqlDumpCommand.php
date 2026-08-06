<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Project\MysqlDumpLoader;
use DockerCli\Project\BackupStorageLocator;
use DockerCli\Project\ProjectRegistry;
use DockerCli\Panel\BackupsSettingsRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MysqlDumpCommand extends AbstractCommand
{
    public function __construct(private readonly ?ProjectRegistry $registry = null, private readonly ?MysqlDumpLoader $dumpLoader = null, private readonly ?BackupStorageLocator $storageLocator = null, private readonly ?BackupsSettingsRepository $backupsSettings = null)
    {
        parent::__construct('mysql:dump');
        $this->setDescription('Создать параллельный дамп MySQL-базы проекта через mydumper.');
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Код зарегистрированного проекта.');
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Путь к директории создаваемого бэкапа.');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Короткое имя директории бэкапа.');
        $this->addOption('location', null, InputOption::VALUE_REQUIRED, 'Код централизованного хранилища бэкапов.');
        $this->addOption('strategy', null, InputOption::VALUE_REQUIRED, 'Код стратегии БД.');
        $this->addOption('comment', null, InputOption::VALUE_REQUIRED, 'Комментарий к бэкапу.');
        $this->addOption('threads', 'j', InputOption::VALUE_REQUIRED, 'Число параллельных потоков.', '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->registry ?? new ProjectRegistry();
        $project = $input->getOption('project') ?: $registry->projectNameFromContext();
        if (!is_string($project) || !$registry->hasProject($project)) {
            $this->writeMessage($output, '<error>Укажите зарегистрированный проект через --project или запустите команду из проекта.</error>');
            return Command::FAILURE;
        }
        $threads = filter_var($input->getOption('threads'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($threads === false) {
            $this->writeMessage($output, '<error>Опция --threads должна быть положительным целым числом.</error>');
            return Command::INVALID;
        }
        $database = $registry->readProjectConfig($project)['data']['databases']['mysql']['database'] ?? $project;
        if (!is_string($database) || $database === '') {
            $this->writeMessage($output, sprintf('<error>В конфигурации проекта "%s" не задана база MySQL.</error>', $project));
            return Command::FAILURE;
        }
        $name = $input->getOption('name');
        $path = $input->getOption('path');
        $location = $input->getOption('location');
        if ($name !== null && $path !== null) {
            $this->writeMessage($output, '<error>Опции --name и --path нельзя использовать одновременно.</error>');
            return Command::INVALID;
        }
        if ($name !== null && (!is_string($name) || $name === '' || basename($name) !== $name)) {
            $this->writeMessage($output, '<error>Опция --name должна содержать короткое имя директории бэкапа.</error>');
            return Command::INVALID;
        }
        if ($path !== null && (!is_string($path) || $path === '')) {
            $this->writeMessage($output, '<error>Опция --path должна содержать путь к директории бэкапа.</error>');
            return Command::INVALID;
        }
        if ($location !== null && (!is_string($location) || $location === '' || $name === null || $path !== null)) {
            $this->writeMessage($output, '<error>Опцию --location можно использовать только вместе с --name.</error>');
            return Command::INVALID;
        }
        try {
            $backupRoot = $location === null ? '.docker-cli/backups/mysql' : ($this->storageLocator ?? new BackupStorageLocator())->databaseDirectory($location, 'mysql');
        } catch (\InvalidArgumentException $exception) {
            $this->writeMessage($output, '<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        }
        $path = $path ?? sprintf('%s/%s', $backupRoot, $name ?? sprintf('%s-%s', $project, date('Ymd-His')));
        $path = $this->absolutePath($path);
        $strategy = $this->resolveStrategy($input, $output);
        if ($strategy === false) return Command::INVALID;
        try {
            $code = ($this->dumpLoader ?? new MysqlDumpLoader())->dump($database, $path, $threads, $output, $strategy['databaseInclude'] ?? [], $strategy['databaseExclude'] ?? []);
        } catch (MissingConfigException) {
            $this->writeMessage($output, '<error>Системная конфигурация не инициализирована.</error>');
            return Command::FAILURE;
        }
        if ($code === Command::SUCCESS) {
            $metadata = ['project' => $project, 'database' => $database, 'createdAt' => date(DATE_ATOM)];
            if (is_string($input->getOption('comment')) && trim($input->getOption('comment')) !== '') $metadata['comment'] = trim($input->getOption('comment'));
            if (is_array($strategy)) { $metadata['databaseStrategy'] = $strategy['code']; $metadata['databaseStrategyTables'] = ['include' => $strategy['databaseInclude'], 'exclude' => $strategy['databaseExclude']]; }
            file_put_contents($path . '/docker-cli.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            CommandContext::fromEnvironment($this, $output)->addMessage(new Message(
                sprintf('Бэкап MySQL-базы "%s" проекта "%s" создан: "%s".', $database, $project, basename($path)),
                MessageLevel::Info,
                notify: true,
            ));
        }
        return $code;
    }

    private function resolveStrategy(InputInterface $input, OutputInterface $output): array|false|null
    {
        $code = $input->getOption('strategy');
        if ($code === null) return null;
        if (!is_string($code) || $code === '') { $this->writeMessage($output, '<error>Опция --strategy должна содержать код стратегии БД.</error>'); return false; }
        foreach (($this->backupsSettings ?? new BackupsSettingsRepository())->fileStrategies() as $strategy) if ($strategy['code'] === $code) return $strategy;
        $this->writeMessage($output, sprintf('<error>Стратегия БД с кодом «%s» не найдена.</error>', $code));
        return false;
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? rtrim($path, DIRECTORY_SEPARATOR) : rtrim((getcwd() ?: '.') . DIRECTORY_SEPARATOR . $path, DIRECTORY_SEPARATOR);
    }
}
