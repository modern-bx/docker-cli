<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use DockerCli\Config\SystemCompose;
use DockerCli\Panel\Dto\ProjectDto;
use DockerCli\Panel\Dto\ProjectListDto;
use DockerCli\Panel\Dto\ConceptDto;
use DockerCli\Panel\Dto\DeploymentScriptDto;
use DockerCli\Panel\Dto\ProjectOptionsDto;
use DockerCli\Panel\Dto\ProjectBackupListDto;
use DockerCli\Panel\Dto\QueuedOperationDto;
use DockerCli\Panel\Dto\ScheduleListDto;
use DockerCli\Panel\Dto\Request\ProjectBackupListRequestDto;
use DockerCli\Panel\Dto\Request\ProjectBackupCreateRequestDto;
use DockerCli\Panel\Dto\Request\ProjectBackupRestoreRequestDto;
use DockerCli\Panel\Dto\Request\ProjectCreateRequestDto;
use DockerCli\Panel\Dto\Request\ProjectCloneRequestDto;
use DockerCli\Panel\Dto\Request\EmptyRequestDto;
use DockerCli\Panel\Dto\Request\ProjectActionRequestDto;
use DockerCli\Panel\Dto\Request\ProjectNotesRequestDto;
use DockerCli\Panel\Dto\Request\ProjectUpdateRequestDto;
use DockerCli\Panel\Dto\Request\ProjectSecurityRequestDto;
use DockerCli\Panel\Dto\Request\ProjectScheduleRequestDto;
use DockerCli\Panel\Dto\Request\ProjectNameRequestDto;
use DockerCli\Panel\Dto\Request\ProjectScheduleItemRequestDto;
use DockerCli\Panel\Enum\ProjectActionEnum;
use DockerCli\Panel\Http\Attribute\Route;
use DockerCli\Project\OpenRestyHostRenderer;
use DockerCli\Project\OfeliaConfigRenderer;
use DockerCli\Project\OfeliaReloadScheduler;
use DockerCli\Project\PhpLanguageVersion;
use DockerCli\Project\ProjectRegistry;
use DockerCli\Project\TreeArchiveVolumes;
use DockerCli\Project\ProjectNameGenerator;
use DockerCli\Queue\QueueRepository;
use DockerCli\Queue\QueueItemValidator;
use DockerCli\Task\TaskRepository;
use Symfony\Component\Yaml\Yaml;
use function DockerCli\Util\join_path;

final class ProjectController
{
    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly ?SystemCompose $compose = null,
        private readonly ?QueueRepository $queues = null,
        private readonly ?ProjectsSettingsRepository $settings = null,
        private readonly ?TaskRepository $tasks = null,
        private readonly ?BackupsSettingsRepository $backupSettings = null,
    )
    {
    }

    #[Route('POST', '/api/projects/{name}/{action:' . ProjectActionEnum::ROUTE_PATTERN . '}', ProjectActionRequestDto::class, ProjectListDto::class)]
    public function action(ProjectActionRequestDto $request): ProjectListDto
    {
        $name = $request->name;
        $action = $request->action;
        if (!$this->projects->hasProject($name)) {
            throw new ProjectActionException('Проект не найден.', 404);
        }
        $config = $this->projects->readProjectConfig($name);
        $project = is_array($config['data']['project'] ?? null) ? $config['data']['project'] : null;
        if ($project === null) {
            throw new ProjectActionException('Конфигурация проекта повреждена.', 422);
        }

        if (ProjectActionEnum::isEnable($action) || ProjectActionEnum::isDisable($action)) {
            $config['data']['project']['enabled'] = ProjectActionEnum::isEnable($action);
            $this->projects->writeProjectConfig($name, $config);
            (new OpenRestyHostRenderer())->render();
            $this->reloadOpenResty();
        } elseif (ProjectActionEnum::isWipe($action)) {
            if (($project['protected'] ?? false) === true) {
                throw new ProjectActionException('Проект защищен.', 409);
            }
            $this->enqueueWipe($name);
        } elseif (ProjectActionEnum::isDelete($action)) {
            if (($project['protected'] ?? false) === true) {
                throw new ProjectActionException('Проект защищен.', 409);
            }
            $this->enqueueDelete($name);
        } else {
            throw new ProjectActionException('Неизвестное действие.', 400);
        }

        return $this->projects(new EmptyRequestDto());
    }

    #[Route('GET', '/api/projects', EmptyRequestDto::class, ProjectListDto::class)]
    public function projects(EmptyRequestDto $request): ProjectListDto
    {
        $projects = [];
        $baseHost = ($this->compose ?? new SystemCompose())->envValue('BASE_HOST', '');
        foreach ($this->projects->registeredProjectNames() as $name) {
            $config = $this->projects->readProjectConfig($name);
            $project = is_array($config['data']['project'] ?? null) ? $config['data']['project'] : [];
            $projectName = is_string($project['name'] ?? null) && $project['name'] !== '' ? $project['name'] : $name;
            $projects[] = new ProjectDto(
                name: $projectName,
                language: $this->concept($project['language'] ?? null, ['php' => 'PHP']),
                languageVersion: ($project['language'] ?? null) === 'php'
                    ? (PhpLanguageVersion::isSupported($project['language_version'] ?? null) ? $project['language_version'] : PhpLanguageVersion::default($this->compose))
                    : null,
                framework: $this->concept($project['framework'] ?? null, self::FRAMEWORK_NAMES),
                // Older project configs predate this flag and are enabled by default,
                // just like OpenRestyHostRenderer treats them.
                enabled: ($project['enabled'] ?? true) !== false,
                protected: ($project['protected'] ?? false) === true,
                url: $baseHost !== '' ? sprintf('https://web-%s.%s', $projectName, $baseHost) : null,
                tags: $this->tags($project['tags'] ?? []),
                description: is_string($project['description'] ?? null) ? $project['description'] : '',
                root: is_string($project['root'] ?? null) ? $project['root'] : '',
            );
        }

        return new ProjectListDto($projects);
    }

    #[Route('GET', '/api/projects/{name}/schedule', ProjectNameRequestDto::class, ScheduleListDto::class)]
    public function schedule(ProjectNameRequestDto $request): ScheduleListDto
    {
        if (!$this->projects->hasProject($request->name)) throw new ProjectActionException('Проект не найден.', 404);
        $config = $this->projects->readProjectConfig($request->name);
        $items = $config['data']['project']['schedule'] ?? [];
        if (!is_array($items)) $items = [];

        return new ScheduleListDto(array_values(array_map(static function (array $item): array {
            $item['enabled'] = ($item['enabled'] ?? true) !== false;
            return $item;
        }, array_filter($items, static fn (mixed $item): bool => is_array($item)))));
    }

    #[Route('POST', '/api/projects/{name}/schedule', ProjectScheduleRequestDto::class, ScheduleListDto::class)]
    public function addSchedule(ProjectScheduleRequestDto $request): ScheduleListDto
    {
        if (!$this->projects->hasProject($request->name)) throw new ProjectActionException('Проект не найден.', 404);
        if (count(preg_split('/\s+/', $request->schedule) ?: []) !== 5) {
            throw new ProjectActionException('Расписание должно состоять из пяти полей cron.', 422);
        }
        $config = $this->projects->readProjectConfig($request->name);
        if (!is_array($config['data']['project'] ?? null)) throw new ProjectActionException('Конфигурация проекта повреждена.', 422);
        $items = $config['data']['project']['schedule'] ?? [];
        if (!is_array($items)) $items = [];
        $items[] = ['enabled' => $request->enabled, 'schedule' => $request->schedule, 'command' => $request->command, 'workingDirectory' => $request->workingDirectory];
        $this->writeSchedule($request->name, $config, $items);

        return new ScheduleListDto($items);
    }

    #[Route('POST', '/api/projects/{name}/schedule/{index:\d+}', ProjectScheduleRequestDto::class, ScheduleListDto::class)]
    public function updateSchedule(ProjectScheduleRequestDto $request): ScheduleListDto
    {
        [$config, $items] = $this->scheduleConfig($request->name);
        if ($request->index === null || !isset($items[$request->index])) throw new ProjectActionException('Запись расписания не найдена.', 404);
        if (count(preg_split('/\s+/', $request->schedule) ?: []) !== 5) throw new ProjectActionException('Расписание должно состоять из пяти полей cron.', 422);
        $items[$request->index] = ['enabled' => $request->enabled, 'schedule' => $request->schedule, 'command' => $request->command, 'workingDirectory' => $request->workingDirectory];
        $this->writeSchedule($request->name, $config, $items);

        return new ScheduleListDto($items);
    }

    #[Route('DELETE', '/api/projects/{name}/schedule/{index:\d+}', ProjectScheduleItemRequestDto::class, ScheduleListDto::class)]
    public function deleteSchedule(ProjectScheduleItemRequestDto $request): ScheduleListDto
    {
        [$config, $items] = $this->scheduleConfig($request->name);
        if (!isset($items[$request->index])) throw new ProjectActionException('Запись расписания не найдена.', 404);
        array_splice($items, $request->index, 1);
        $this->writeSchedule($request->name, $config, $items);

        return new ScheduleListDto($items);
    }

    /** @return array{array<string, mixed>, list<mixed>} */
    private function scheduleConfig(string $name): array
    {
        if (!$this->projects->hasProject($name)) throw new ProjectActionException('Проект не найден.', 404);
        $config = $this->projects->readProjectConfig($name);
        if (!is_array($config['data']['project'] ?? null)) throw new ProjectActionException('Конфигурация проекта повреждена.', 422);
        $items = $config['data']['project']['schedule'] ?? [];

        return [$config, is_array($items) ? array_values($items) : []];
    }

    /** @param array<string, mixed> $config @param list<mixed> $items */
    private function writeSchedule(string $name, array $config, array $items): void
    {
        $config['data']['project']['schedule'] = $items;
        $this->projects->writeProjectConfig($name, $config);
        $root = $config['data']['project']['root'] ?? null;
        if (is_string($root) && $root !== '') {
            $repositoryConfigFile = join_path($root, '.docker-cli', 'project.yaml');
            $repositoryConfig = is_file($repositoryConfigFile) ? Yaml::parseFile($repositoryConfigFile) : [];
            if (!is_array($repositoryConfig)) $repositoryConfig = [];
            $repositoryConfig['data']['project']['schedule'] = $items;
            file_put_contents($repositoryConfigFile, Yaml::dump($repositoryConfig, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        }
        (new OfeliaConfigRenderer($this->projects, $this->compose))->render();
        (new OfeliaReloadScheduler($this->queues))->enqueue();
    }

    #[Route('GET', '/api/projects/{name}/backups', ProjectBackupListRequestDto::class, ProjectBackupListDto::class)]
    public function backups(ProjectBackupListRequestDto $request): ProjectBackupListDto
    {
        if (!$this->projects->hasProject($request->name)) throw new ProjectActionException('Проект не найден.', 404);
        $config = $this->projects->readProjectConfig($request->name);
        $root = $config['data']['project']['root'] ?? null;
        if (!is_string($root) || $root === '') throw new ProjectActionException('Конфигурация проекта повреждена.', 422);

        $grouped = [];
        $strategies = array_column(($this->backupSettings ?? new BackupsSettingsRepository())->fileStrategies(), 'name', 'code');
        $locations = [['path' => join_path($root, '.docker-cli', 'backups'), 'code' => '', 'name' => 'Папка проекта']];
        foreach (($this->backupSettings ?? new BackupsSettingsRepository())->locations() as $location) {
            $locations[] = ['path' => $location['path'], 'code' => $location['code'], 'name' => $location['code']];
        }
        foreach ($locations as $location) {
            foreach (['mysql' => 'MySQL', 'postgres' => 'PostgreSQL'] as $databaseCode => $databaseName) {
                $directory = join_path($location['path'], $databaseCode);
                foreach (glob(join_path($directory, '*'), GLOB_ONLYDIR) ?: [] as $backup) {
                    $timestamp = filemtime($backup);
                    $metadataFile = join_path($backup, 'docker-cli.json');
                    $metadata = is_file($metadataFile) ? json_decode((string) file_get_contents($metadataFile), true) : null;
                    if ($location['code'] !== '' && (!is_array($metadata) || ($metadata['project'] ?? null) !== $request->name)) continue;
                    $createdAt = is_array($metadata) && is_string($metadata['createdAt'] ?? null) ? strtotime($metadata['createdAt']) : false;
                    $key = $location['code'] . "\0" . basename($backup);
                    if (!isset($grouped[$key])) $grouped[$key] = [
                        'name' => basename($backup),
                        'date' => gmdate(DATE_ATOM, $createdAt !== false ? $createdAt : ($timestamp === false ? 0 : $timestamp)),
                        'composition' => 'БД',
                        'size' => 0,
                        'sizeParts' => [],
                        'database' => null,
                        'databaseCode' => '',
                        'databaseCodes' => [],
                        'databaseStrategy' => null,
                        'databaseStrategyCode' => '',
                        'databaseStrategyTables' => null,
                        'strategy' => null,
                        'strategyCode' => '',
                        'strategyPaths' => null,
                        'hasDatabase' => true,
                        'hasFiles' => false,
                        'location' => $location['code'],
                        'locationName' => $location['name'],
                    ];
                    $databaseStrategyCode = is_array($metadata) && is_string($metadata['databaseStrategy'] ?? null) ? $metadata['databaseStrategy'] : '';
                    $databaseStrategyNames = array_column(($this->backupSettings ?? new BackupsSettingsRepository())->fileStrategies(), 'name', 'code');
                    $grouped[$key]['databaseStrategyCode'] = $databaseStrategyCode;
                    $grouped[$key]['databaseStrategy'] = $databaseStrategyCode !== '' ? ($databaseStrategyNames[$databaseStrategyCode] ?? null) : null;
                    $grouped[$key]['databaseStrategyTables'] = is_array($metadata['databaseStrategyTables'] ?? null) ? $metadata['databaseStrategyTables'] : ['include' => [], 'exclude' => []];
                    $grouped[$key]['strategyCode'] = $databaseStrategyCode;
                    $grouped[$key]['strategy'] = $grouped[$key]['databaseStrategy'];
                    $grouped[$key]['databaseCodes'][] = $databaseCode;
                    $grouped[$key]['database'] = implode(', ', array_map(static fn (string $code): string => ['mysql' => 'MySQL', 'postgres' => 'PostgreSQL'][$code], $grouped[$key]['databaseCodes']));
                    $grouped[$key]['databaseCode'] = $grouped[$key]['databaseCodes'][0];
                    $databaseSize = $this->directorySize($backup);
                    $grouped[$key]['size'] += $databaseSize;
                    $grouped[$key]['sizeParts'][] = ['type' => $databaseCode, 'name' => $databaseName, 'size' => $databaseSize];
                }
            }
            $directory = join_path($location['path'], 'tree');
            foreach (glob(join_path($directory, '*'), GLOB_ONLYDIR) ?: [] as $backup) {
                $metadataFile = join_path($backup, 'docker-cli.json');
                $metadata = is_file($metadataFile) ? json_decode((string) file_get_contents($metadataFile), true) : null;
                if (!is_array($metadata) || ($metadata['project'] ?? null) !== $request->name) continue;
                $archive = $metadata['archive'] ?? null;
                if (!is_string($archive)) continue;
                $volumeErrors = (new TreeArchiveVolumes())->validate($backup, $metadata);
                $timestamp = filemtime($backup);
                $createdAt = is_string($metadata['createdAt'] ?? null) ? strtotime($metadata['createdAt']) : false;
                $strategyCode = is_string($metadata['strategy'] ?? null) ? $metadata['strategy'] : '';
                $strategyPaths = is_array($metadata['strategyPaths'] ?? null) ? $metadata['strategyPaths'] : ['include' => [], 'exclude' => []];
                $fileSize = $this->directorySize($backup);
                $volumeCount = is_array($metadata['volumes'] ?? null) && is_int($metadata['volumes']['chunkCount'] ?? null)
                    ? $metadata['volumes']['chunkCount'] : 1;
                $fileData = [
                    'name' => basename($backup),
                    'date' => gmdate(DATE_ATOM, $createdAt !== false ? $createdAt : ($timestamp === false ? 0 : $timestamp)),
                    'composition' => 'Файлы',
                    'size' => $fileSize,
                    'sizeParts' => [['type' => 'files', 'name' => 'Файлы', 'size' => $fileSize, 'volumeCount' => $volumeCount]],
                    'database' => null,
                    'databaseCode' => '',
                    'strategy' => $strategyCode !== '' ? ($strategies[$strategyCode] ?? null) : null,
                    'strategyCode' => $strategyCode,
                    'strategyPaths' => $strategyPaths,
                    'hasDatabase' => false,
                    'hasFiles' => true,
                    'filesValid' => $volumeErrors === [],
                    'filesError' => $volumeErrors === [] ? null : implode(' ', $volumeErrors),
                    'location' => $location['code'],
                    'locationName' => $location['name'],
                ];
                $key = $location['code'] . "\0" . basename($backup);
                if (isset($grouped[$key])) {
                    $grouped[$key]['composition'] = 'БД и файлы';
                    $grouped[$key]['size'] += $fileData['size'];
                    $grouped[$key]['sizeParts'][] = $fileData['sizeParts'][0];
                    $grouped[$key]['strategy'] = $fileData['strategy'];
                    $grouped[$key]['strategyCode'] = $fileData['strategyCode'];
                    $grouped[$key]['strategyPaths'] = $fileData['strategyPaths'];
                    $grouped[$key]['hasFiles'] = true;
                    $grouped[$key]['filesValid'] = $fileData['filesValid'];
                    $grouped[$key]['filesError'] = $fileData['filesError'];
                } else {
                    $fileData['databaseCodes'] = [];
                    $grouped[$key] = $fileData;
                }
            }
        }
        $items = array_values($grouped);
        $items = array_values(array_filter($items, static function (array $item) use ($request): bool {
            $date = substr($item['date'], 0, 10);
            return ($request->backupName === '' || str_contains(mb_strtolower($item['name']), mb_strtolower($request->backupName)))
                && ($request->composition === 'all' || ($request->composition === 'database' && $item['composition'] === 'БД') || ($request->composition === 'files' && $item['composition'] === 'Файлы') || ($request->composition === 'database-files' && $item['composition'] === 'БД и файлы'))
                && ($request->database === 'all' || in_array($request->database, $item['databaseCodes'], true))
                && ($request->strategy === 'all' || ($request->strategy === 'none' ? $item['strategyCode'] === '' : $request->strategy === $item['strategyCode']))
                && ($request->location === 'all' || ($request->location === 'project' ? $item['location'] === '' : $request->location === $item['location']))
                && ($request->dateFrom === null || $date >= $request->dateFrom)
                && ($request->dateTo === null || $date <= $request->dateTo);
        }));
        usort($items, static function (array $left, array $right) use ($request): int {
            $comparison = $left[$request->sort] <=> $right[$request->sort];
            if ($comparison === 0) $comparison = $left['name'] <=> $right['name'];
            return $request->direction === 'asc' ? $comparison : -$comparison;
        });
        $total = count($items);
        $items = array_slice($items, ($request->page - 1) * $request->pageSize, $request->pageSize);
        return new ProjectBackupListDto($items, $total, $request->page, $request->pageSize);
    }

    #[Route('POST', '/api/projects/{name}/backups', ProjectBackupCreateRequestDto::class, QueuedOperationDto::class)]
    public function createBackup(ProjectBackupCreateRequestDto $request): QueuedOperationDto
    {
        if (!$this->projects->hasProject($request->name)) throw new ProjectActionException('Проект не найден.', 404);
        if (!$request->database && !$request->files) throw new ProjectActionException('Выберите данные для создания бэкапа.', 422);
        if ($request->database && !$request->mysql && !$request->postgres) throw new ProjectActionException('Выберите хотя бы одну базу данных.', 422);
        if ($request->location !== '' && !in_array($request->location, array_column(($this->backupSettings ?? new BackupsSettingsRepository())->locations(), 'code'), true)) {
            throw new ProjectActionException('Выбранное хранилище бэкапов не найдено.', 422);
        }
        if (($request->files || $request->database) && $request->strategy !== '' && !in_array($request->strategy, array_column(($this->backupSettings ?? new BackupsSettingsRepository())->fileStrategies(), 'code'), true)) {
            throw new ProjectActionException('Выбранная стратегия не найдена.', 422);
        }
        $backupName = sprintf('%s-%s', $request->name, date('Ymd-His'));
        $tasks = [];
        if ($request->database && $request->mysql) {
            $tasks[] = [
                'code' => 'core.mysql.dump',
                'arguments' => ['backup' => ['value' => $backupName], 'location' => ['value' => $request->location], 'strategy' => ['value' => $request->strategy]],
                'project' => $request->name,
            ];
        }
        if ($request->database && $request->postgres) {
            $tasks[] = [
                'code' => 'core.postgres.dump',
                'arguments' => ['backup' => ['value' => $backupName], 'location' => ['value' => $request->location], 'strategy' => ['value' => $request->strategy]],
                'project' => $request->name,
            ];
        }
        if ($request->files) {
            $tasks[] = [
                'code' => 'core.tree.dump',
                'arguments' => [
                    'backup' => ['value' => $backupName],
                    'location' => ['value' => $request->location],
                    'strategy' => ['value' => $request->strategy],
                    'compress' => ['value' => $request->compress],
                    'chunk-size' => ['value' => $request->chunkSize],
                    'chunk-count' => ['value' => $request->chunkCount],
                ],
                'project' => $request->name,
            ];
        }
        $item = ['meta' => ['schema' => 'queue-item', 'version' => '0.1'], 'queue-item' => ['tasks' => $tasks]];
        $operationCode = $request->database ? ($request->mysql ? 'core.mysql.dump' : 'core.postgres.dump') : 'core.tree.dump';
        try {
            $file = ($this->queues ?? new QueueRepository())->create('default', $operationCode, $item);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            throw new ProjectActionException($exception->getMessage(), 500);
        }
        return new QueuedOperationDto($file);
    }

    #[Route('POST', '/api/projects/{name}/backups/{backup}/restore', ProjectBackupRestoreRequestDto::class, QueuedOperationDto::class)]
    public function restoreBackup(ProjectBackupRestoreRequestDto $request): QueuedOperationDto
    {
        if (!$this->projects->hasProject($request->name)) throw new ProjectActionException('Проект не найден.', 404);
        if ($this->projects->isProjectProtected($request->name)) throw new ProjectActionException('Проект защищен.', 409);
        $config = $this->projects->readProjectConfig($request->name);
        $root = $config['data']['project']['root'] ?? null;
        if (!is_string($root) || $root === '') throw new ProjectActionException('Конфигурация проекта повреждена.', 422);

        $backupDirectory = join_path($root, '.docker-cli', 'backups');
        if ($request->location !== '') {
            $location = null;
            foreach (($this->backupSettings ?? new BackupsSettingsRepository())->locations() as $candidate) {
                if ($candidate['code'] === $request->location) {
                    $location = $candidate;
                    break;
                }
            }
            if ($location === null) throw new ProjectActionException('Хранилище бэкапов не найдено.', 404);
            $backupDirectory = $location['path'];
        }
        $tasks = [];
        $databases = $request->databases !== [] ? $request->databases : ($request->database !== '' ? [$request->database] : []);
        foreach ($databases as $database) {
            $backup = $this->backupPath($backupDirectory, $database, $request->backup, $request->name, $request->location !== '');
            $tasks[] = [
                'code' => 'core.' . $database . '.load',
                'arguments' => ['path' => ['value' => $backup]],
                'project' => $request->name,
            ];
        }
        if ($request->files) {
            $backup = $this->backupPath($backupDirectory, 'tree', $request->backup, $request->name, true);
            $metadata = json_decode((string) @file_get_contents(join_path($backup, 'docker-cli.json')), true);
            $volumeErrors = is_array($metadata) ? (new TreeArchiveVolumes())->validate($backup, $metadata) : ['Метаданные файлового бэкапа повреждены.'];
            if ($volumeErrors !== []) throw new ProjectActionException('Файловый бэкап повреждён: ' . implode(' ', $volumeErrors), 422);
            $tasks[] = [
                'code' => 'core.tree.load',
                'arguments' => [
                    'path' => ['value' => $backup],
                    'force' => ['value' => $request->force],
                    'wipe' => ['value' => $request->wipe],
                ],
                'project' => $request->name,
            ];
        }
        $taskCode = $databases !== [] ? 'core.' . $databases[0] . '.load' : 'core.tree.load';
        $item = ['meta' => ['schema' => 'queue-item', 'version' => '0.1'], 'queue-item' => ['tasks' => $tasks]];
        try {
            $file = ($this->queues ?? new QueueRepository())->create('default', $taskCode, $item);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            throw new ProjectActionException($exception->getMessage(), 500);
        }
        return new QueuedOperationDto($file);
    }

    private function backupPath(string $directory, string $type, string $name, string $project, bool $checkMetadata): string
    {
        $root = realpath(join_path($directory, $type));
        $backup = $root === false ? false : realpath(join_path($root, $name));
        if ($backup === false || !is_dir($backup) || !str_starts_with($backup . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
            throw new ProjectActionException('Бэкап не найден.', 404);
        }
        if ($checkMetadata) {
            $metadata = json_decode((string) @file_get_contents(join_path($backup, 'docker-cli.json')), true);
            if (!is_array($metadata) || ($metadata['project'] ?? null) !== $project) throw new ProjectActionException('Бэкап не найден.', 404);
        }
        return $backup;
    }

    #[Route('POST', '/api/projects/{name}/backups/{backup}/delete', ProjectBackupRestoreRequestDto::class, QueuedOperationDto::class)]
    public function deleteBackup(ProjectBackupRestoreRequestDto $request): QueuedOperationDto
    {
        if (!$this->projects->hasProject($request->name)) throw new ProjectActionException('Проект не найден.', 404);
        if ($this->projects->isProjectProtected($request->name)) throw new ProjectActionException('Проект защищен.', 409);
        $databases = $request->databases !== [] ? $request->databases : ($request->database !== '' ? [$request->database] : []);
        $tasks = [];
        foreach ($databases as $database) $tasks[] = [
            'code' => 'core.' . $database . '.backup-delete',
            'arguments' => ['backup' => ['value' => $request->backup], 'location' => ['value' => $request->location]],
            'project' => $request->name,
        ];
        if ($request->files) $tasks[] = [
            'code' => 'core.tree.backup-delete',
            'arguments' => ['backup' => ['value' => $request->backup], 'location' => ['value' => $request->location]],
            'project' => $request->name,
        ];
        $taskCode = $databases !== [] ? 'core.' . $databases[0] . '.backup-delete' : 'core.tree.backup-delete';
        $item = ['meta' => ['schema' => 'queue-item', 'version' => '0.1'], 'queue-item' => ['tasks' => $tasks]];
        try {
            $file = ($this->queues ?? new QueueRepository())->create('default', $taskCode, $item);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            throw new ProjectActionException($exception->getMessage(), 500);
        }
        return new QueuedOperationDto($file);
    }

    private const FRAMEWORK_NAMES = ['symfony' => 'Symfony', 'laravel' => 'Laravel', 'bitrix' => 'Bitrix', 'bitrix24' => 'Bitrix24'];

    #[Route('GET', '/api/projects/options', EmptyRequestDto::class, ProjectOptionsDto::class)]
    public function options(EmptyRequestDto $request): ProjectOptionsDto
    {
        $deploymentScripts = [];
        foreach (($this->tasks ?? new TaskRepository())->all() as $definition) {
            $task = $definition['task'];
            if (($task['context'] ?? null) !== 'project' || !in_array('project:init', $task['tags'] ?? [], true)) continue;
            $deploymentScripts[] = new DeploymentScriptDto(
                (string) $task['code'],
                is_string($task['name'] ?? null) ? $task['name'] : (string) $task['code'],
                is_array($task['parameters'] ?? null) ? $task['parameters'] : [],
            );
        }
        return new ProjectOptionsDto(
            ($this->settings ?? new ProjectsSettingsRepository())->locations(),
            [new ConceptDto('php', 'PHP')],
            PhpLanguageVersion::SUPPORTED,
            PhpLanguageVersion::default($this->compose),
            ['php' => [new ConceptDto('', 'Без фреймворка'), ...array_map(static fn (string $code, string $name) => new ConceptDto($code, $name), array_keys(self::FRAMEWORK_NAMES), self::FRAMEWORK_NAMES)]],
            $deploymentScripts,
        );
    }

    #[Route('POST', '/api/projects', ProjectCreateRequestDto::class, ProjectListDto::class)]
    public function create(ProjectCreateRequestDto $request): ProjectListDto
    {
        $options = $this->options(new EmptyRequestDto());
        $location = current(array_filter($options->locations, static fn (array $item): bool => $item['code'] === $request->location));
        if (!is_array($location)) throw new ProjectActionException('Локация не найдена.', 422);
        if ($request->language !== 'php' || !isset($options->frameworks[$request->language]) || !in_array($request->framework ?? '', array_map(static fn (ConceptDto $item) => $item->code, $options->frameworks[$request->language]), true)) {
            throw new ProjectActionException('Язык или фреймворк не поддерживается.', 422);
        }
        $name = $request->code ?? (new ProjectNameGenerator())->generate($this->projects->registeredProjectNames());
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name) !== 1) throw new ProjectActionException('Код проекта должен содержать строчные латинские буквы, цифры и дефисы.', 422);
        if ($this->projects->hasProject($name) || file_exists(join_path($location['path'], $name))) throw new ProjectActionException('Проект или директория с таким кодом уже существует.', 409);
        $arguments = [
                'location' => ['value' => $location['path']], 'name' => ['value' => $name],
                'language' => ['value' => $request->language],
        ];
        if ($request->framework !== null) $arguments['framework'] = ['value' => $request->framework];
        $queuedTasks = [[
            'code' => 'core.project.up', 'arguments' => $arguments, 'project' => $name,
        ]];
        if ($request->deploymentScript !== null) {
            $script = current(array_filter($options->deploymentScripts, static fn (DeploymentScriptDto $item): bool => $item->code === $request->deploymentScript));
            if (!$script instanceof DeploymentScriptDto) throw new ProjectActionException('Скрипт развертки не найден.', 422);
            $scriptArguments = [];
            foreach ($request->deploymentArguments as $argumentName => $value) {
                if (!is_string($argumentName)) throw new ProjectActionException('Некорректные аргументы скрипта развертки.', 422);
                if ($value === '' && isset($script->parameters[$argumentName]) && ($script->parameters[$argumentName]['required'] ?? false) !== true) continue;
                $scriptArguments[$argumentName] = ['value' => $value];
            }
            $queuedTasks[] = ['code' => $script->code, 'arguments' => $scriptArguments, 'project' => $name];
        } elseif ($request->deploymentArguments !== []) {
            throw new ProjectActionException('Аргументы переданы без скрипта развертки.', 422);
        }
        $item = ['meta' => ['schema' => 'queue-item', 'version' => '0.1'], 'queue-item' => ['tasks' => $queuedTasks]];
        $validationErrors = (new QueueItemValidator($this->tasks ?? new TaskRepository()))->validate($item);
        if ($validationErrors !== []) throw new ProjectActionException(implode("\n", $validationErrors), 422);
        try { ($this->queues ?? new QueueRepository())->create('default', 'core.project.up', $item); }
        catch (\InvalidArgumentException|\RuntimeException $exception) { throw new ProjectActionException($exception->getMessage(), 500); }
        return $this->projects(new EmptyRequestDto());
    }

    #[Route('POST', '/api/projects/{name}/clone', ProjectCloneRequestDto::class, QueuedOperationDto::class)]
    public function clone(ProjectCloneRequestDto $request): QueuedOperationDto
    {
        if (!$this->projects->hasProject($request->name)) throw new ProjectActionException('Проект не найден.', 404);
        $arguments = ['from' => ['value' => $request->name], 'here' => ['value' => true]];
        if ($request->to !== null) $arguments['to'] = ['value' => $request->to];
        if ($request->skipDb || $request->dbms === []) $arguments['skip-db'] = ['value' => true];
        else $arguments['dbms'] = ['value' => implode(',', $request->dbms)];
        $item = ['meta' => ['schema' => 'queue-item', 'version' => '0.1'], 'queue-item' => ['tasks' => [[
            'code' => 'core.project.clone', 'arguments' => $arguments,
        ]]]];
        $validationErrors = (new QueueItemValidator($this->tasks ?? new TaskRepository()))->validate($item);
        if ($validationErrors !== []) throw new ProjectActionException(implode("\n", $validationErrors), 422);
        try { $file = ($this->queues ?? new QueueRepository())->create('default', 'core.project.clone', $item); }
        catch (\InvalidArgumentException|\RuntimeException $exception) { throw new ProjectActionException($exception->getMessage(), 500); }
        return new QueuedOperationDto($file);
    }

    #[Route('POST', '/api/projects/{name}/update', ProjectUpdateRequestDto::class, ProjectListDto::class)]
    public function update(ProjectUpdateRequestDto $request): ProjectListDto
    {
        if (!$this->projects->hasProject($request->project)) throw new ProjectActionException('Проект не найден.', 404);
        if ($request->name !== null && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $request->name) !== 1) throw new ProjectActionException('Имя проекта должно содержать строчные латинские буквы, цифры и дефисы.', 422);
        if ($request->name !== null && $request->name !== $request->project && $this->projects->hasProject($request->name)) throw new ProjectActionException('Проект с таким именем уже существует.', 409);
        $options = $this->options(new EmptyRequestDto());
        if ($request->language !== null && !isset($options->frameworks[$request->language])) throw new ProjectActionException('Язык не поддерживается.', 422);
        $language = $request->language ?? 'php';
        if ($request->languageVersion !== null && ($language !== 'php' || !PhpLanguageVersion::isSupported($request->languageVersion))) throw new ProjectActionException('Версия языка не поддерживается.', 422);
        if ($request->framework !== null && !in_array($request->framework, array_map(static fn (ConceptDto $item) => $item->code, $options->frameworks[$language] ?? []), true)) throw new ProjectActionException('Фреймворк не поддерживается.', 422);
        $arguments = [];
        foreach (['name', 'language', 'framework'] as $option) {
            if ($request->{$option} !== null) $arguments[$option] = ['value' => $request->{$option}];
        }
        if ($request->languageVersion !== null) $arguments['language_version'] = ['value' => $request->languageVersion];
        $item = ['meta' => ['schema' => 'queue-item', 'version' => '0.1'], 'queue-item' => ['tasks' => [[
            'code' => 'core.project.update', 'arguments' => $arguments, 'project' => $request->project,
        ]]]];
        try { ($this->queues ?? new QueueRepository())->create('default', 'core.project.update', $item); }
        catch (\InvalidArgumentException|\RuntimeException $exception) { throw new ProjectActionException($exception->getMessage(), 500); }
        return $this->projects(new EmptyRequestDto());
    }

    #[Route('POST', '/api/projects/{name}/security', ProjectSecurityRequestDto::class, ProjectListDto::class)]
    public function saveSecurity(ProjectSecurityRequestDto $request): ProjectListDto
    {
        if (!$this->projects->hasProject($request->name)) {
            throw new ProjectActionException('Проект не найден.', 404);
        }
        $config = $this->projects->readProjectConfig($request->name);
        if (!is_array($config['data']['project'] ?? null)) {
            throw new ProjectActionException('Конфигурация проекта повреждена.', 422);
        }
        $config['data']['project']['protected'] = $request->protected;
        $this->projects->writeProjectConfig($request->name, $config);

        return $this->projects(new EmptyRequestDto());
    }

    #[Route('POST', '/api/projects/{name}/notes', ProjectNotesRequestDto::class, ProjectListDto::class)]
    public function saveNotes(ProjectNotesRequestDto $request): ProjectListDto
    {
        $name = $request->name;
        $tags = $request->tags;
        $description = $request->description;
        if (!$this->projects->hasProject($name)) {
            throw new ProjectActionException('Проект не найден.', 404);
        }
        $normalizedTags = [];
        foreach ($tags as $tag) {
            if (!is_string($tag) || preg_match('/^[\p{L}\p{N} -]+$/u', $tag) !== 1) {
                throw new ProjectActionException('Теги могут содержать только буквы, цифры, дефис и пробел.', 422);
            }
            $tag = trim($tag);
            if ($tag !== '' && !in_array($tag, $normalizedTags, true)) {
                $normalizedTags[] = $tag;
            }
        }
        $config = $this->projects->readProjectConfig($name);
        if (!is_array($config['data']['project'] ?? null)) {
            throw new ProjectActionException('Конфигурация проекта повреждена.', 422);
        }
        $config['data']['project']['tags'] = $normalizedTags;
        $config['data']['project']['description'] = $description;
        $this->projects->writeProjectConfig($name, $config);

        return $this->projects(new EmptyRequestDto());
    }

    /** @return list<string> */
    private function tags(mixed $tags): array
    {
        if (!is_array($tags)) return [];

        return array_values(array_filter($tags, static fn (mixed $tag): bool => is_string($tag) && $tag !== ''));
    }

    private function directorySize(string $directory): int
    {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && !$file->isLink()) $size += $file->getSize();
        }
        return $size;
    }

    /** @param array<string, string> $names */
    private function concept(mixed $value, array $names): ?ConceptDto
    {
        if (!is_string($value) || $value === '' || !isset($names[$value])) return null;
        return new ConceptDto($value, $names[$value]);
    }

    private function enqueueWipe(string $name): void
    {
        $item = [
            'meta' => ['schema' => 'queue-item', 'version' => '0.1'],
            'queue-item' => ['tasks' => [[
                'code' => 'core.project.wipe',
                'arguments' => [],
                'project' => $name,
            ]]],
        ];
        try {
            ($this->queues ?? new QueueRepository())->create('default', 'core.project.wipe', $item);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            throw new ProjectActionException($exception->getMessage(), 500);
        }
    }

    private function enqueueDelete(string $name): void
    {
        $item = [
            'meta' => ['schema' => 'queue-item', 'version' => '0.1'],
            'queue-item' => ['tasks' => [[
                'code' => 'core.project.down',
                'arguments' => [
                    'wipe' => ['value' => true],
                    'erase' => ['value' => true],
                    'drop' => ['value' => true],
                    'force' => ['value' => true],
                ],
                'project' => $name,
            ]]],
        ];
        try {
            ($this->queues ?? new QueueRepository())->create('default', 'core.project.down', $item);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            throw new ProjectActionException($exception->getMessage(), 500);
        }
    }

    private function reloadOpenResty(): void
    {
        $compose = $this->compose ?? new SystemCompose();
        $command = [...$compose->dockerComposeCommand('exec'), '--no-TTY', 'openresty', 'openresty', '-s', 'reload'];
        $process = proc_open($command, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $compose->directory(), $compose->dockerProcessEnvironment());
        if (!is_resource($process)) throw new ProjectActionException('Не удалось перезагрузить конфигурацию OpenResty.');
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) throw new ProjectActionException(trim($stdout . "\n" . $stderr) ?: 'Не удалось перезагрузить конфигурацию OpenResty.');
    }
}
