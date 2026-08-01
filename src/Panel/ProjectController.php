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
use DockerCli\Panel\Dto\Request\ProjectBackupListRequestDto;
use DockerCli\Panel\Dto\Request\ProjectBackupCreateRequestDto;
use DockerCli\Panel\Dto\Request\ProjectBackupRestoreRequestDto;
use DockerCli\Panel\Dto\Request\ProjectCreateRequestDto;
use DockerCli\Panel\Dto\Request\EmptyRequestDto;
use DockerCli\Panel\Dto\Request\ProjectActionRequestDto;
use DockerCli\Panel\Dto\Request\ProjectNotesRequestDto;
use DockerCli\Panel\Dto\Request\ProjectRenameRequestDto;
use DockerCli\Panel\Dto\Request\ProjectSecurityRequestDto;
use DockerCli\Panel\Enum\ProjectActionEnum;
use DockerCli\Panel\Http\Attribute\Route;
use DockerCli\Project\OpenRestyHostRenderer;
use DockerCli\Project\ProjectRegistry;
use DockerCli\Project\ProjectNameGenerator;
use DockerCli\Queue\QueueRepository;
use DockerCli\Queue\QueueItemValidator;
use DockerCli\Task\TaskRepository;
use function DockerCli\Util\join_path;

final class ProjectController
{
    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly ?SystemCompose $compose = null,
        private readonly ?QueueRepository $queues = null,
        private readonly ?ProjectsSettingsRepository $settings = null,
        private readonly ?TaskRepository $tasks = null,
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
                framework: $this->concept($project['framework'] ?? null, self::FRAMEWORK_NAMES),
                // Older project configs predate this flag and are enabled by default,
                // just like OpenRestyHostRenderer treats them.
                enabled: ($project['enabled'] ?? true) !== false,
                protected: ($project['protected'] ?? false) === true,
                url: $baseHost !== '' ? sprintf('https://web-%s.%s', $projectName, $baseHost) : null,
                tags: $this->tags($project['tags'] ?? []),
                description: is_string($project['description'] ?? null) ? $project['description'] : '',
            );
        }

        return new ProjectListDto($projects);
    }

    #[Route('GET', '/api/projects/{name}/backups', ProjectBackupListRequestDto::class, ProjectBackupListDto::class)]
    public function backups(ProjectBackupListRequestDto $request): ProjectBackupListDto
    {
        if (!$this->projects->hasProject($request->name)) throw new ProjectActionException('Проект не найден.', 404);
        $config = $this->projects->readProjectConfig($request->name);
        $root = $config['data']['project']['root'] ?? null;
        if (!is_string($root) || $root === '') throw new ProjectActionException('Конфигурация проекта повреждена.', 422);

        $items = [];
        foreach (['mysql' => 'MySQL', 'postgres' => 'PostgreSQL'] as $databaseCode => $databaseName) {
            $directory = join_path($root, '.docker-cli', 'backups', $databaseCode);
            foreach (glob(join_path($directory, '*'), GLOB_ONLYDIR) ?: [] as $backup) {
                $timestamp = filemtime($backup);
                $metadataFile = join_path($backup, 'docker-cli.json');
                $metadata = is_file($metadataFile) ? json_decode((string) file_get_contents($metadataFile), true) : null;
                $createdAt = is_array($metadata) && is_string($metadata['createdAt'] ?? null) ? strtotime($metadata['createdAt']) : false;
                $items[] = [
                    'name' => basename($backup),
                    'date' => gmdate(DATE_ATOM, $createdAt !== false ? $createdAt : ($timestamp === false ? 0 : $timestamp)),
                    'composition' => 'БД',
                    'size' => $this->directorySize($backup),
                    'database' => $databaseName,
                    'databaseCode' => $databaseCode,
                ];
            }
        }
        $items = array_values(array_filter($items, static function (array $item) use ($request): bool {
            $date = substr($item['date'], 0, 10);
            return ($request->backupName === '' || str_contains(mb_strtolower($item['name']), mb_strtolower($request->backupName)))
                && ($request->composition === 'all' || $request->composition === 'database')
                && ($request->database === 'all' || $request->database === $item['databaseCode'])
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
        if ($request->files) throw new ProjectActionException('Создание бэкапа файлов пока не реализовано.', 422);
        if (!$request->mysql && !$request->postgres) throw new ProjectActionException('Выберите хотя бы одну базу данных.', 422);
        $backupName = sprintf('%s-%s', $request->name, date('Ymd-His'));
        $tasks = [];
        if ($request->mysql) {
            $tasks[] = [
                'code' => 'core.mysql.dump',
                'arguments' => ['backup' => ['value' => $backupName]],
                'project' => $request->name,
            ];
        }
        if ($request->postgres) {
            $tasks[] = [
                'code' => 'core.postgres.dump',
                'arguments' => ['backup' => ['value' => $backupName]],
                'project' => $request->name,
            ];
        }
        $item = ['meta' => ['schema' => 'queue-item', 'version' => '0.1'], 'queue-item' => ['tasks' => $tasks]];
        $operationCode = $request->mysql ? 'core.mysql.dump' : 'core.postgres.dump';
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

        $backupRoot = realpath(join_path($root, '.docker-cli', 'backups', $request->database));
        $backup = $backupRoot === false ? false : realpath(join_path($backupRoot, $request->backup));
        if ($backup === false || !is_dir($backup) || !str_starts_with($backup . DIRECTORY_SEPARATOR, $backupRoot . DIRECTORY_SEPARATOR)) {
            throw new ProjectActionException('Бэкап не найден.', 404);
        }

        $taskCode = 'core.' . $request->database . '.load';
        $item = ['meta' => ['schema' => 'queue-item', 'version' => '0.1'], 'queue-item' => ['tasks' => [[
            'code' => $taskCode,
            'arguments' => ['path' => ['value' => $backup]],
            'project' => $request->name,
        ]]]];
        try {
            $file = ($this->queues ?? new QueueRepository())->create('default', $taskCode, $item);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            throw new ProjectActionException($exception->getMessage(), 500);
        }
        return new QueuedOperationDto($file);
    }

    #[Route('POST', '/api/projects/{name}/backups/{backup}/delete', ProjectBackupRestoreRequestDto::class, QueuedOperationDto::class)]
    public function deleteBackup(ProjectBackupRestoreRequestDto $request): QueuedOperationDto
    {
        if (!$this->projects->hasProject($request->name)) throw new ProjectActionException('Проект не найден.', 404);
        if ($this->projects->isProjectProtected($request->name)) throw new ProjectActionException('Проект защищен.', 409);
        $item = ['meta' => ['schema' => 'queue-item', 'version' => '0.1'], 'queue-item' => ['tasks' => [[
            'code' => 'core.mysql.backup-delete',
            'arguments' => ['backup' => ['value' => $request->backup]],
            'project' => $request->name,
        ]]]];
        try {
            $file = ($this->queues ?? new QueueRepository())->create('default', 'core.mysql.backup-delete', $item);
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

    #[Route('POST', '/api/projects/{name}/rename', ProjectRenameRequestDto::class, ProjectListDto::class)]
    public function rename(ProjectRenameRequestDto $request): ProjectListDto
    {
        if (!$this->projects->hasProject($request->name)) throw new ProjectActionException('Проект не найден.', 404);
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $request->code) !== 1) throw new ProjectActionException('Код проекта должен содержать строчные латинские буквы, цифры и дефисы.', 422);
        if ($request->code !== $request->name && $this->projects->hasProject($request->code)) throw new ProjectActionException('Проект с таким кодом уже существует.', 409);
        $item = ['meta' => ['schema' => 'queue-item', 'version' => '0.1'], 'queue-item' => ['tasks' => [[
            'code' => 'core.project.rename',
            'arguments' => ['code' => ['value' => $request->code]],
            'project' => $request->name,
        ]]]];
        try { ($this->queues ?? new QueueRepository())->create('default', 'core.project.rename', $item); }
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
