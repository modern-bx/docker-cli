<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Hook\CommandHookRunner;
use DockerCli\Panel\ProjectsSettingsRepository;
use DockerCli\Project\ConfigurableServicesRestarter;
use DockerCli\Project\DataInitializer;
use DockerCli\Project\MysqlDumpLoader;
use DockerCli\Project\OpenRestyHostRenderer;
use DockerCli\Project\ProjectDatabaseConfig;
use DockerCli\Project\ProjectNameGenerator;
use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

use function DockerCli\Util\join_path;

final class ProjectCloneCommand extends AbstractCommand
{
    public function __construct(
        private readonly ?ProjectRegistry $registry = null,
        private readonly ?ProjectsSettingsRepository $settings = null,
        private readonly ?MysqlDumpLoader $mysqlDumpLoader = null,
        private readonly ?DataInitializer $dataInitializer = null,
        private readonly ?CommandHookRunner $hookRunner = null,
    )
    {
        parent::__construct('project:clone');
        $this->setDescription('Полностью клонировать зарегистрированный проект.');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Очистить существующий целевой проект перед клонированием.');
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Кодовое имя исходного проекта.');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Кодовое имя или путь целевого проекта.');
        $this->addOption('location', null, InputOption::VALUE_REQUIRED, 'Код расположения проектов.');
        $this->addOption('here', null, InputOption::VALUE_NONE, 'Создать проект рядом с исходным.');
        $this->addOption('exclude', null, InputOption::VALUE_REQUIRED, 'Список glob-шаблонов через запятую.');
        $this->addOption('skip-db', null, InputOption::VALUE_NONE, 'Не клонировать базы данных.');
        $this->addOption('dbms', null, InputOption::VALUE_REQUIRED, 'Список СУБД для клонирования через запятую.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('here') && $input->getOption('location') !== null) {
            $this->writeMessage($output, '<error>Опции --here и --location нельзя использовать одновременно.</error>');
            return Command::FAILURE;
        }
        if ($input->getOption('skip-db') && $input->getOption('dbms') !== null) {
            $this->writeMessage($output, '<error>Опции --skip-db и --dbms нельзя использовать одновременно.</error>');
            return Command::FAILURE;
        }
        $dbms = $this->resolveDbms($input->getOption('dbms'), (bool) $input->getOption('skip-db'), $output);
        if ($dbms === null) return Command::FAILURE;
        $registry = $this->registry ?? new ProjectRegistry();
        $from = $input->getOption('from');
        $from = is_string($from) && $from !== '' ? $from : $registry->projectNameFromContext();
        if ($from === null || !$registry->hasProject($from)) {
            $this->writeMessage($output, '<error>Исходный проект не найден. Укажите его код через --from.</error>');
            return Command::FAILURE;
        }
        $sourceConfig = $registry->readProjectConfig($from);
        $sourceRoot = $sourceConfig['data']['project']['root'] ?? null;
        if (!is_string($sourceRoot) || !is_dir($sourceRoot)) {
            $this->writeMessage($output, '<error>Директория исходного проекта не найдена.</error>');
            return Command::FAILURE;
        }

        $to = $input->getOption('to');
        $isPath = is_string($to) && (str_contains($to, '/') || str_contains($to, '\\'));
        $names = $registry->registeredProjectNames();
        $name = is_string($to) && $to !== '' && !$isPath ? $to : null;
        if ($name === null && $isPath) $name = $this->normalizeName(basename((string) $to));
        if ($name === null || $name === '' || in_array($name, $names, true) && $isPath) {
            $name = (new ProjectNameGenerator())->generate($names);
        }
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name) !== 1) {
            $this->writeMessage($output, '<error>Некорректное кодовое имя целевого проекта.</error>');
            return Command::FAILURE;
        }

        try {
            $destination = $isPath ? $this->absolutePath((string) $to) : $this->destinationForName($name, $sourceRoot, $input);
        } catch (\InvalidArgumentException $exception) {
            $this->writeMessage($output, '<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $existingName = $this->projectAtPath($destination, $registry);
        if (($existingName !== null || $registry->hasProject($name) || $this->directoryHasFiles($destination)) && !$input->getOption('force')) {
            $this->writeMessage($output, '<error>Целевой проект или директория уже существует. Используйте --force для очистки.</error>');
            return Command::FAILURE;
        }
        if ($input->getOption('force') && is_dir($destination)) $this->wipe($destination);
        if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
            $this->writeMessage($output, '<error>Не удалось создать целевую директорию.</error>');
            return Command::FAILURE;
        }

        $hookArguments = $input instanceof ArgvInput ? $input->getRawTokens(true) : [];
        $beforeHookCode = ($this->hookRunner ?? new CommandHookRunner())->run('project:clone', 'before', $hookArguments);
        if ($beforeHookCode !== Command::SUCCESS) {
            return $beforeHookCode;
        }

        $startedAt = new \DateTimeImmutable();
        $started = microtime(true);
        $this->writeMessage($output, sprintf(
            '<info>Клонирование проекта "%s" в "%s" началось (%s).</info>',
            $from,
            $name,
            $startedAt->format('H:i:s'),
        ));

        $config = $sourceConfig;
        $documentRoot = $config['data']['project']['document_root'] ?? $sourceRoot;
        $relativeDocumentRoot = is_string($documentRoot) && str_starts_with($documentRoot, rtrim($sourceRoot, '/') . '/')
            ? substr($documentRoot, strlen(rtrim($sourceRoot, '/')) + 1) : '';
        $config['data']['project']['name'] = $name;
        $config['data']['project']['root'] = $destination;
        $config['data']['project']['document_root'] = $relativeDocumentRoot === '' ? $destination : join_path($destination, $relativeDocumentRoot);
        $config = (new ProjectDatabaseConfig())->ensure($config);
        if (!is_dir($registry->projectDirectory($name))) mkdir($registry->projectDirectory($name), 0775, true);
        $registry->writeProjectConfig($name, $config);
        $metadata = join_path($destination, '.docker-cli');
        if (!is_dir($metadata)) mkdir($metadata, 0775, true);
        file_put_contents(join_path($metadata, 'project.yaml'), Yaml::dump(['meta' => ['schema' => 'project-meta', 'version' => 0.1], 'data' => ['project' => ['name' => $name]]], 4, 2));

        $excludes = ['.docker-cli'];
        $rawExclude = $input->getOption('exclude');
        if (is_string($rawExclude)) foreach (explode(',', $rawExclude) as $pattern) if (trim($pattern) !== '') $excludes[] = ltrim(trim($pattern), './');
        $excludeArgs = implode(' ', array_map(static fn (string $pattern): string => '--exclude=' . escapeshellarg($pattern), $excludes));
        $command = sprintf('tar %s -cf - . | (cd %s && tar -xf -)', $excludeArgs, escapeshellarg($destination));
        $filesStarted = microtime(true);
        passthru('cd ' . escapeshellarg($sourceRoot) . ' && ' . $command, $status);
        $filesDuration = microtime(true) - $filesStarted;
        if ($status !== 0) {
            $this->writeMessage($output, '<error>Копирование проекта завершилось с ошибкой.</error>');
            return Command::FAILURE;
        }
        $databaseDuration = 0.0;
        if ($dbms !== []) {
            $databaseStarted = microtime(true);
            $databaseCode = $this->initializeTargetDatabases($config, $name, $output);
            if ($databaseCode === Command::SUCCESS && in_array('mysql', $dbms, true)) {
                $databaseCode = $this->cloneMysqlDatabase($sourceConfig, $config, $output);
            }
            if ($databaseCode === Command::SUCCESS && in_array('postgres', $dbms, true)) {
                $databaseCode = $this->clonePostgresDatabase($sourceConfig, $config, $output);
            }
            $databaseDuration = microtime(true) - $databaseStarted;
            if ($databaseCode !== Command::SUCCESS) return $databaseCode;
        }
        try {
            (new OpenRestyHostRenderer())->render();
        } catch (\RuntimeException $exception) {
            $this->writeMessage($output, '<error>Не удалось пересобрать конфигурацию хостов OpenResty: ' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $restartCode = (new ConfigurableServicesRestarter())->restart($output);
        if ($restartCode !== Command::SUCCESS) return $restartCode;

        $this->writeMessage($output, sprintf(
            '<info>Проект "%s" клонирован в "%s" (%s; всего: %s; копирование файлов: %s%s).</info>',
            $name,
            $destination,
            (new \DateTimeImmutable())->format('H:i:s'),
            $this->formatDuration(microtime(true) - $started),
            $this->formatDuration($filesDuration),
            $databaseDuration > 0 ? '; копирование БД: ' . $this->formatDuration($databaseDuration) : '',
        ));
        return ($this->hookRunner ?? new CommandHookRunner())->run('project:clone', 'after', $hookArguments);
    }

    private function destinationForName(string $name, string $sourceRoot, InputInterface $input): string
    {
        if ($input->getOption('here')) return join_path(dirname($sourceRoot), $name);
        $locations = ($this->settings ?? new ProjectsSettingsRepository())->locations();
        $code = $input->getOption('location');
        foreach ($locations as $location) {
            if (($code !== null && $location['code'] === $code) || ($code === null && $location['default'])) return join_path($location['path'], $name);
        }
        throw new \InvalidArgumentException($code === null ? 'Не настроено расположение проектов по умолчанию.' : sprintf('Расположение "%s" не найдено.', $code));
    }

    private function absolutePath(string $path): string { return str_starts_with($path, '/') ? rtrim($path, '/') : join_path((string) getcwd(), $path); }
    private function normalizeName(string $name): string { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-'); }
    private function formatDuration(float $seconds): string
    {
        $remaining = max(0, (int) round($seconds));
        $parts = [];
        foreach ([[86400, 'день', 'дня', 'дней'], [3600, 'час', 'часа', 'часов'], [60, 'минута', 'минуты', 'минут']] as [$size, $one, $few, $many]) {
            $value = intdiv($remaining, $size);
            if ($value > 0) {
                $parts[] = $value . ' ' . $this->plural($value, $one, $few, $many);
                $remaining %= $size;
            }
        }
        if ($remaining > 0 || $parts === []) $parts[] = $remaining . ' ' . $this->plural($remaining, 'секунда', 'секунды', 'секунд');
        return implode(' ', $parts);
    }

    private function plural(int $value, string $one, string $few, string $many): string
    {
        $mod100 = $value % 100;
        if ($mod100 >= 11 && $mod100 <= 14) return $many;
        return match ($value % 10) { 1 => $one, 2, 3, 4 => $few, default => $many };
    }

    /** @return list<string>|null */
    private function resolveDbms(mixed $option, bool $skip, OutputInterface $output): ?array
    {
        if ($skip) return [];
        $explicit = is_string($option);
        $dbms = $explicit ? array_values(array_unique(array_filter(array_map('trim', explode(',', $option))))) : ['mysql', 'postgres'];
        if ($dbms === [] || array_diff($dbms, ['mysql', 'postgres']) !== []) {
            $this->writeMessage($output, '<error>Опция --dbms должна содержать mysql и/или postgres.</error>');
            return null;
        }
        return $dbms;
    }

    /** @param array<string, mixed> $targetConfig */
    private function initializeTargetDatabases(array $targetConfig, string $targetName, OutputInterface $output): int
    {
        $mysqlPassword = $targetConfig['data']['databases']['mysql']['password'] ?? null;
        $postgresPassword = $targetConfig['data']['databases']['postgres']['password'] ?? null;
        if (!is_string($mysqlPassword) || !is_string($postgresPassword)) {
            $this->writeMessage($output, '<error>В конфигурации проекта отсутствуют пароли баз данных.</error>');
            return Command::FAILURE;
        }
        try {
            return ($this->dataInitializer ?? new DataInitializer())->initialize($targetName, $mysqlPassword, $postgresPassword, false, $output);
        } catch (MissingConfigException $exception) {
            $this->writeMessage($output, sprintf('<error>Системная конфигурация не инициализирована. Отсутствуют файлы: %s.</error>', implode(', ', $exception->missingFiles())));
            return Command::FAILURE;
        }
    }

    /** @param array<string, mixed> $sourceConfig @param array<string, mixed> $targetConfig */
    private function cloneMysqlDatabase(array $sourceConfig, array $targetConfig, OutputInterface $output): int
    {
        $source = $sourceConfig['data']['databases']['mysql']['database'] ?? null;
        $target = $targetConfig['data']['databases']['mysql']['database'] ?? null;
        if (!is_string($source) || $source === '' || !is_string($target) || $target === '') {
            $this->writeMessage($output, '<error>В конфигурации проекта отсутствуют параметры баз данных.</error>');
            return Command::FAILURE;
        }
        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            $this->writeMessage($output, '<error>Не удалось определить домашнюю директорию для временного снимка БД.</error>');
            return Command::FAILURE;
        }
        $snapshot = join_path($home, '.config', 'docker-cli', 'cache', 'project-clone', bin2hex(random_bytes(8)));
        $loader = $this->mysqlDumpLoader ?? new MysqlDumpLoader();
        try {
            $code = $loader->dump($source, $snapshot, 4, $output);
            if ($code !== Command::SUCCESS) return $code;
            return $loader->load($target, $snapshot, 4, false, $output);
        } catch (MissingConfigException $exception) {
            $this->writeMessage($output, sprintf('<error>Системная конфигурация не инициализирована. Отсутствуют файлы: %s.</error>', implode(', ', $exception->missingFiles())));
            return Command::FAILURE;
        } finally {
            if (is_dir($snapshot)) $this->remove($snapshot);
        }
    }

    /** @param array<string, mixed> $sourceConfig @param array<string, mixed> $targetConfig */
    private function clonePostgresDatabase(array $sourceConfig, array $targetConfig, OutputInterface $output): int
    {
        $source = $sourceConfig['data']['databases']['postgres']['database'] ?? null;
        $target = $targetConfig['data']['databases']['postgres']['database'] ?? null;
        $sourceUser = $sourceConfig['data']['databases']['postgres']['username'] ?? $source;
        $targetUser = $targetConfig['data']['databases']['postgres']['username'] ?? $target;
        if (!is_string($source) || $source === '' || !is_string($target) || $target === ''
            || !is_string($sourceUser) || $sourceUser === '' || !is_string($targetUser) || $targetUser === '') {
            $this->writeMessage($output, '<error>В конфигурации проекта отсутствуют параметры PostgreSQL.</error>');
            return Command::FAILURE;
        }
        try {
            return ($this->dataInitializer ?? new DataInitializer())->clonePostgres($source, $target, $sourceUser, $targetUser, $output);
        } catch (MissingConfigException $exception) {
            $this->writeMessage($output, sprintf('<error>Системная конфигурация не инициализирована. Отсутствуют файлы: %s.</error>', implode(', ', $exception->missingFiles())));
            return Command::FAILURE;
        }
    }
    private function directoryHasFiles(string $path): bool { return is_dir($path) && count(scandir($path) ?: []) > 2; }
    private function projectAtPath(string $path, ProjectRegistry $registry): ?string
    {
        foreach ($registry->registeredProjectNames() as $name) if (($registry->readProjectConfig($name)['data']['project']['root'] ?? null) === $path) return $name;
        return null;
    }
    private function wipe(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.docker-cli') continue;
            $this->remove(join_path($path, $entry));
        }
    }
    private function remove(string $path): void
    {
        if (is_link($path) || !is_dir($path)) { unlink($path); return; }
        foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $this->remove(join_path($path, $entry));
        rmdir($path);
    }
}
