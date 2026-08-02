<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Panel\BackupsSettingsRepository;
use DockerCli\Project\BackupStorageLocator;
use DockerCli\Project\ProjectRegistry;
use DockerCli\Project\TreeArchiveLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function DockerCli\Util\join_path;

final class TreeLoadCommand extends AbstractCommand
{
    public function __construct(
        private readonly ?ProjectRegistry $registry = null,
        private readonly ?TreeArchiveLoader $loader = null,
        private readonly ?BackupStorageLocator $storageLocator = null,
        private readonly ?BackupsSettingsRepository $backupsSettings = null,
    ) {
        parent::__construct('tree:load');
        $this->setDescription('Восстановить файлы проекта из бэкапа tree:dump.');
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Код зарегистрированного проекта.');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Короткое имя директории бэкапа.');
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Путь к директории, созданной tree:dump.');
        $this->addOption('location', null, InputOption::VALUE_REQUIRED, 'Код централизованного хранилища бэкапов.');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Разрешить перезапись существующих файлов.');
        $this->addOption('wipe', null, InputOption::VALUE_NONE, 'Очистить проект перед восстановлением, сохранив только .docker-cli.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->registry ?? new ProjectRegistry();
        $project = $input->getOption('project') ?: $registry->projectNameFromContext();
        if (!is_string($project) || !$registry->hasProject($project)) {
            $this->writeMessage($output, '<error>Укажите зарегистрированный проект через --project или запустите команду из проекта.</error>');
            return Command::FAILURE;
        }
        if ($registry->isProjectProtected($project)) {
            $this->writeMessage($output, sprintf('<error>Проект "%s" защищен. Изменение его данных запрещено.</error>', $project));
            return Command::FAILURE;
        }
        $name = $input->getOption('name');
        $path = $input->getOption('path');
        $location = $input->getOption('location');
        if (($name === null) === ($path === null)) {
            $this->writeMessage($output, '<error>Укажите ровно одну из опций --name или --path.</error>');
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
        if ($location !== null && (!is_string($location) || $location === '' || $path !== null)) {
            $this->writeMessage($output, '<error>Опцию --location можно использовать только вместе с --name.</error>');
            return Command::INVALID;
        }
        $root = $registry->readProjectConfig($project)['data']['project']['root'] ?? null;
        if (!is_string($root) || $root === '' || !is_dir($root)) {
            $this->writeMessage($output, '<error>Конфигурация проекта повреждена.</error>');
            return Command::FAILURE;
        }
        try {
            $backupRoot = $location === null
                ? join_path($root, '.docker-cli', 'backups', 'tree')
                : ($this->storageLocator ?? new BackupStorageLocator())->treeDirectory($location);
            $path = realpath($path ?? join_path($backupRoot, (string) $name));
            if ($path === false || !is_file(join_path($path, 'docker-cli.json'))) {
                throw new \InvalidArgumentException('Указанная директория не является файловым бэкапом.');
            }
            $metadata = json_decode((string) file_get_contents(join_path($path, 'docker-cli.json')), true);
            if (!is_array($metadata) || ($metadata['project'] ?? null) !== $project) {
                throw new \InvalidArgumentException('Бэкап создан для другого проекта или содержит некорректные метаданные.');
            }
            $strategyCode = is_string($metadata['strategy'] ?? null) ? $metadata['strategy'] : null;
            $strategyPaths = $metadata['strategyPaths'] ?? null;
            if ($strategyCode !== null && (!is_array($strategyPaths) || !is_array($strategyPaths['include'] ?? null) || !is_array($strategyPaths['exclude'] ?? null))) {
                $strategyPaths = ['include' => [], 'exclude' => []];
            }
            $strategyPaths = is_array($strategyPaths) ? $strategyPaths : ['include' => [], 'exclude' => []];
            foreach (['include', 'exclude'] as $key) {
                if (!is_array($strategyPaths[$key] ?? null) || !array_is_list($strategyPaths[$key])
                    || array_filter($strategyPaths[$key], static fn ($value): bool => !is_string($value))) {
                    throw new \InvalidArgumentException('Метаданные файловой стратегии повреждены.');
                }
            }
            if ($input->getOption('wipe') && $strategyCode !== null) $this->warnStrategyDifference($output, $strategyCode, $strategyPaths);
            ($this->loader ?? new TreeArchiveLoader())->load(
                $path, $root, (bool) $input->getOption('force'), (bool) $input->getOption('wipe'),
            );
        } catch (\InvalidArgumentException $exception) {
            $this->writeMessage($output, '<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        } catch (\RuntimeException $exception) {
            $this->writeMessage($output, '<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
        CommandContext::fromEnvironment($this, $output)->addMessage(new Message(
            sprintf('Файлы проекта "%s" восстановлены из бэкапа "%s".', $project, basename($path)),
            MessageLevel::Info,
            notify: true,
        ));
        return Command::SUCCESS;
    }

    /** @param array{include: list<string>, exclude: list<string>} $savedPaths */
    private function warnStrategyDifference(OutputInterface $output, string $code, array $savedPaths): void
    {
        $current = null;
        foreach (($this->backupsSettings ?? new BackupsSettingsRepository())->fileStrategies() as $strategy) {
            if ($strategy['code'] === $code) { $current = $strategy; break; }
        }
        if ($current === null || $current['include'] !== $savedPaths['include'] || $current['exclude'] !== $savedPaths['exclude']) {
            $this->writeMessage($output, sprintf('<comment>Сохранённые пути стратегии «%s» отличаются от текущих настроек.</comment>', $code));
        }
    }
}
