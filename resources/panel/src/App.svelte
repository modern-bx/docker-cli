<script>
  import { onMount } from 'svelte';
  import { EditorState } from '@codemirror/state';
  import { EditorView, keymap, lineNumbers, highlightActiveLine, highlightActiveLineGutter } from '@codemirror/view';
  import { defaultKeymap, history as cmHistory, historyKeymap } from '@codemirror/commands';
  import { StreamLanguage } from '@codemirror/language';
  import { shell } from '@codemirror/legacy-modes/mode/shell';
  import { aura } from '@uiw/codemirror-theme-aura';
  import { eclipse } from '@uiw/codemirror-theme-eclipse';
  import { githubDark, githubLight } from '@uiw/codemirror-theme-github';
  import { materialDark, materialLight } from '@uiw/codemirror-theme-material';
  import { monokai } from '@uiw/codemirror-theme-monokai';
  import { tokyoNight } from '@uiw/codemirror-theme-tokyo-night';
  import { vscodeDark, vscodeLight } from '@uiw/codemirror-theme-vscode';
  import { Combobox, Dialog, Tabs, Tooltip, useListCollection } from '@skeletonlabs/skeleton-svelte';
  import { Archive, Bell, CircleHelp, Copy, Download, ExternalLink, Lock, Menu, Pencil, Play, Plus, Power, RotateCw, Save, Settings, Square, Trash2, Undo2 } from '@lucide/svelte';
  import { micromark } from 'micromark';
  import BackupDateFilter from './BackupDateFilter.svelte';
  import HttpRefreshBoundary from './HttpRefreshBoundary.svelte';
  import { addProjectSchedule, cloneProject, createHookSettings, createPanelUser, createProject, createProjectBackup, deletePanelUser, deleteProjectBackup, deleteProjectSchedule, deleteHookSettings, getBackupsSettings, getHookContent, getHooksSettings, getLogs, getProjectBackups, getProjectOptions, getProjectSchedule, getProjects, getProjectsSettings, getSecuritySettings, getSystemStatus, getUsersSettings, queueSystemSelfUpdate, toggleHookSettings, updateProject, updateProjectBackupComment, updateProjectSchedule, restoreProjectBackup, rotatePanelUserPassword, runProjectAction, runSystemAction, saveBackupsSettings, runHookSettings, saveHookContent, saveProjectNotes, saveProjectSecurity, saveProjectsSettings, saveSecuritySettings, updatePanelUser } from './api.js';
  import { createRefreshCoordinator } from './refresh.js';

  const THEME_KEY = 'docker-cli-panel-color-theme';
  const MODE_KEY = 'docker-cli-panel-theme';
  const FONT_KEY = 'docker-cli-panel-font';
  const EDITOR_THEME_KEY = 'docker-cli-panel-editor-theme';
  const strategyTabs = [{ value: 'files', sections: [['include', 'Включить', 'Относительные пути или glob-маски, которые нужно включить в файловый бэкап'], ['exclude', 'Исключить', 'Относительные пути или glob-маски, которые нужно исключить из бэкапа']] }, { value: 'database', sections: [['databaseInclude', 'Включить', 'Точные имена таблиц или glob-маски (например, public.*), которые нужно включить в дамп'], ['databaseExclude', 'Исключить', 'Точные имена таблиц или glob-маски, которые нужно исключить из дампа']] }];
  /** @type {[string, string, import('@codemirror/state').Extension, { background: string, foreground: string, gutter: string }][]} */
  const editorThemes = [
    ['github-light', 'GitHub Light', githubLight, { background: '#fff', foreground: '#24292e', gutter: '#fff' }],
    ['github-dark', 'GitHub Dark', githubDark, { background: '#0d1117', foreground: '#c9d1d9', gutter: '#0d1117' }],
    ['vscode-light', 'VS Code Light', vscodeLight, { background: '#fff', foreground: '#9c4668', gutter: '#fff' }],
    ['vscode-dark', 'VS Code Dark', vscodeDark, { background: '#1e1e1e', foreground: '#9cdcfe', gutter: '#1e1e1e' }],
    ['material-light', 'Material Light', materialLight, { background: '#fafafa', foreground: '#90a4ae', gutter: '#fafafa' }],
    ['material-dark', 'Material Dark', materialDark, { background: '#263238', foreground: '#eeffff', gutter: '#263238' }],
    ['eclipse', 'Eclipse', eclipse, { background: '#fff', foreground: '#000', gutter: '#fff' }],
    ['aura', 'Aura', aura, { background: '#21202e', foreground: '#edecee', gutter: '#21202e' }],
    ['monokai', 'Monokai', monokai, { background: '#272822', foreground: '#f8f8f2', gutter: '#272822' }],
    ['tokyo-night', 'Tokyo Night', tokyoNight, { background: '#24283b', foreground: '#a9b1d6', gutter: '#24283b' }],
  ];
  const themes = [
    ['vox', 'Vox'], ['cerberus', 'Cerberus'], ['concord', 'Concord'],
    ['crimson', 'Crimson'], ['dracula', 'Dracula'], ['fennec', 'Fennec'],
    ['hamlindigo', 'Hamlindigo'], ['legacy', 'Legacy'], ['mint', 'Mint'],
    ['modern', 'Modern'], ['mona', 'Mona'], ['nosh', 'Nosh'],
    ['nouveau', 'Nouveau'], ['pine', 'Pine'], ['reign', 'Reign'],
    ['rocket', 'Rocket'], ['rose', 'Rose'], ['rosepine', 'Rosé Pine'],
    ['sahara', 'Sahara'], ['seafoam', 'Seafoam'], ['terminus', 'Terminus'],
    ['vintage', 'Vintage'], ['wintry', 'Wintry'],
  ];
  const modes = [
    ['light', 'Светлая'], ['dark', 'Тёмная'], ['system', 'Системная'],
  ];
  const projectDetailTabs = ['info', 'notes', 'security', 'backups', 'scheduler', 'journal'];
  const cronTemplates = [['* * * * *', 'Каждую минуту'], ['0 * * * *', 'Каждый час'], ['0 0 * * *', 'Каждый день в полночь'], ['0 9 * * 1-5', 'По будням в 09:00'], ['0 0 * * 0', 'Каждое воскресенье'], ['0 0 1 * *', 'Первого числа месяца']];
  const scheduleStatusOptions = [{ value: 'all', label: 'Все статусы' }, { value: 'enabled', label: 'Включена' }, { value: 'disabled', label: 'Выключена' }];
  const scheduleStatusCollection = useListCollection({ items: scheduleStatusOptions });
  const hookLevelOptions = [{ value: 'all', label: 'Все уровни' }, { value: 'command', label: 'Команда' }];
  const hookTimingOptions = [{ value: 'all', label: 'Любое время' }, { value: 'before', label: 'before' }, { value: 'after', label: 'after' }];
  const hookEnabledOptions = [{ value: 'all', label: 'Все' }, { value: 'enabled', label: 'Да' }, { value: 'disabled', label: 'Нет' }];
  const hookCreateLevelOptions = [{ value: 'command', label: 'Команда' }];
  const hookCreateTimingOptions = [{ value: 'before', label: 'before' }, { value: 'after', label: 'after' }];
  const hookLevelCollection = useListCollection({ items: hookLevelOptions });
  const hookTimingCollection = useListCollection({ items: hookTimingOptions });
  const hookEnabledCollection = useListCollection({ items: hookEnabledOptions });
  const hookCreateLevelCollection = useListCollection({ items: hookCreateLevelOptions });
  const hookCreateTimingCollection = useListCollection({ items: hookCreateTimingOptions });
  let hookProjectCollection = useListCollection({ items: [{ value: '', label: 'Проект не выбран' }] });
  const fonts = [
    { value: 'ubuntu', label: 'Ubuntu Regular' },
    { value: 'noto', label: 'Noto Sans' },
  ];
  const fontCollection = useListCollection({ items: fonts });
  const logTypes = [{ value: 'queue', label: 'Очередь' }, { value: 'hook', label: 'Хук' }];
  const logTypeCollection = useListCollection({ items: logTypes });
  const logStatuses = [
    { value: 'all', label: 'Все статусы' },
    { value: '10-pending', label: 'Ожидание (10)' },
    { value: '20-active', label: 'Выполняется (20)' },
    { value: '30-success', label: 'Успешно (30)' },
    { value: '40-failure', label: 'Не выполнено (40)' },
    { value: '50-error', label: 'Ошибка (50)' },
    { value: '90-archive', label: 'Архив (90)' },
  ];
  const logStatusCollection = useListCollection({ items: logStatuses });
  const logLevels = [{ value: 'all', label: 'Все уровни' }, { value: 'debug', label: 'Отладка' }, { value: 'info', label: 'Информация' }, { value: 'warning', label: 'Предупреждение' }, { value: 'error', label: 'Ошибка' }];
  const logContexts = [{ value: 'all', label: 'Все контексты' }, { value: 'command', label: 'Команда' }, { value: 'task', label: 'Задача' }, { value: 'queue', label: 'Очередь' }, { value: 'hook', label: 'Хук' }];
  const logLevelCollection = useListCollection({ items: logLevels });
  const logContextCollection = useListCollection({ items: logContexts });
  const logCategoryFilters = [{ field: 'level', label: 'Уровень', items: logLevels, collection: logLevelCollection }, { field: 'context', label: 'Контекст', items: logContexts, collection: logContextCollection }];
  const pageSizeCollection = useListCollection({ items: [25, 50, 100].map((value) => ({ value: String(value), label: String(value) })) });
  let login = '';
  let password = '';
  let currentLogin = '';
  let authenticated = false;
  let error = '';
  let errorStatus = 0;
  let errorTitle = 'Ошибка';
  let loading = true;
  let submitting = false;
  let profileOpen = false;
  let themeOpen = false;
  let editorThemeOpen = false;
  let notificationsOpen = false;
  let notifications = [];
  let notificationsInitialized = false;
  const knownNotificationFiles = new Set();
  let theme = 'vox';
  let editorTheme = 'github-light';
  let mode = 'system';
  let font = 'ubuntu';
  let systemDark = false;
  let projects = [];
  let selectedProjectName = '';
  let projectDetailTab = 'info';
  let activeSection = 'projects';
  let settingsTab = 'projects';
  let logItems = [];
  let logProjects = [];
  let logType = ['queue'];
  let logProject = ['all'];
  let logStatus = ['all'];
  let logLevel = ['all'];
  let logContext = ['all'];
  let logQueueItem = '';
  let logItemCode = '';
  let logTaskCode = '';
  let logHook = '';
  let logCommand = '';
  let logTiming = '';
  let logHookLevel = '';
  let logPage = 1;
  let logPageSize = 25;
  let logTotal = 0;
  let logSort = 'timestamp';
  let logDirection = 'desc';
  let logsLoading = false;
  let logProjectCollection = useListCollection({ items: [{ value: 'all', label: 'Все проекты' }] });
  let logFilterTimer = null;
  let logRequestId = 0;
  let projectsLoading = false;
  let projectsError = '';
  let projectQuery = '';
  let projectTags = [];
  let systemOpen = false;
  let queueOpen = false;
  let queueItems = [];
  let queuePaused = false;
  let queueActionPending = false;
  let queueConfirmation = null;
  let systemStatus = 'stopped';
  let systemServices = [];
  let systemPending = false;
  let systemUpdatePending = false;
  let systemPendingMessage = '';
  let queuedOperationNotice = '';
  let systemConfirmation = null;
  let projectConfirmation = null;
  let projectContextMenu = null;
  let projectAddDialog = null;
  let projectCloneDialog = null;
  let projectCloning = false;
  let projectUpdateDialog = null;
  let projectUpdating = false;
  let projectAddOptions = { locations: [], databaseLocations: [], languages: [], languageVersions: [], defaultLanguageVersion: '8.2', frameworks: {}, deploymentScripts: [] };
  let projectLocationCollection = useListCollection({ items: [] });
  let projectCloneLocationCollection = useListCollection({ items: [] });
  let projectLanguageCollection = useListCollection({ items: [] });
  let projectLanguageVersionCollection = useListCollection({ items: [] });
  let projectFrameworkCollection = useListCollection({ items: [] });
  let projectDeploymentCollection = useListCollection({ items: [] });
  let projectDatabaseLocationCollection = useListCollection({ items: [] });
  let projectAdding = false;
  let backupItems = [];
  let backupTotal = 0;
  let backupPage = 1;
  let backupPageSize = 25;
  let backupName = '';
  let backupComposition = 'all';
  let backupDatabase = 'all';
  let backupStrategy = 'all';
  let backupLocation = 'all';
  let backupDateFrom = '';
  let backupDateTo = '';
  let backupSort = 'date';
  let backupDirection = 'desc';
  let backupsLoading = false;
  let backupFilterTimer = null;
  let backupRequestId = 0;
  let backupContextMenu = null;
  let backupRestoreConfirmation = null;
  let backupRestorePending = false;
  let backupCreateDialog = null;
  let backupCreatePending = false;
  let backupCommentDialog = null;
  let backupCommentPending = false;
  let backupDeleteConfirmation = null;
  let backupDeletePending = false;
  let scheduleItems = [];
  let scheduleLoading = false;
  let scheduleDialog = null;
  let scheduleSaving = false;
  let scheduleQuery = '';
  let scheduleStatus = 'all';
  let schedulePage = 1;
  let schedulePageSize = 25;
  let scheduleContextMenu = null;
  let scheduleDeleteConfirmation = null;
  let scheduleDeleting = false;
  let scheduleToggleConfirmation = null;
  let scheduleToggling = false;
  const backupCompositionOptions = [{ value: 'all', label: 'Любой состав' }, { value: 'database', label: 'БД' }, { value: 'files', label: 'Файлы' }, { value: 'database-files', label: 'БД и файлы' }];
  const backupDatabaseOptions = [{ value: 'all', label: 'Любая СУБД' }, { value: 'mysql', label: 'MySQL' }, { value: 'postgres', label: 'PostgreSQL' }];
  const backupCompositionCollection = useListCollection({ items: backupCompositionOptions });
  const backupDatabaseCollection = useListCollection({ items: backupDatabaseOptions });
  let backupStrategyFilterOptions = [{ value: 'all', label: 'Любая файловая стратегия' }, { value: 'none', label: 'Без файловой стратегии' }];
  let backupStrategyFilterCollection = useListCollection({ items: backupStrategyFilterOptions });
  let backupCreateStrategyOptions = [{ value: '', label: 'Без стратегии' }];
  let backupCreateStrategyCollection = useListCollection({ items: backupCreateStrategyOptions });
  const backupCompressionOptions = [{ value: '', label: 'Без сжатия' }, { value: 'gzip', label: 'Gzip' }, { value: 'bzip2', label: 'Bzip2' }, { value: 'xz', label: 'XZ' }, { value: 'zstd', label: 'Zstandard' }, { value: 'lz4', label: 'LZ4' }, { value: 'zip', label: 'ZIP' }];
  const backupCompressionCollection = useListCollection({ items: backupCompressionOptions });
  let backupStorageOptions = [{ value: '', label: 'Папка проекта' }];
  let backupStorageCollection = useListCollection({ items: backupStorageOptions });
  let backupLocationFilterOptions = [{ value: 'all', label: 'Все расположения' }, { value: 'project', label: 'Папка проекта' }];
  let backupLocationFilterCollection = useListCollection({ items: backupLocationFilterOptions });
  let notesProjectName = '';
  let noteTags = [];
  let noteTagInput = '';
  let noteDescription = '';
  let notesSaving = false;
  let securitySaving = false;
  let maximumSessionHours = 8;
  let httpAuthLogin = '';
  let httpAuthPassword = '';
  let settingsLoading = false;
  let settingsSaving = false;
  let projectLocations = [{ path: '', default: true }];
  let projectDatabaseLocations = [{ path: '', code: '', default: false }];
  let projectSettingsLoading = false;
  let projectSettingsSaving = false;
  let backupLocations = [{ path: '', code: '', default: true }];
  let backupFileStrategies = [{ name: '', code: '', include: [], exclude: [], databaseInclude: [], databaseExclude: [] }];
  let fileStrategyDialog = null;
  let backupSettingsLoading = false;
  let backupSettingsSaving = false;
  let hooks = [];
  let hookCommands = [];
  let hookCommandCollection = useListCollection({ items: [] });
  let hookCreateDialog = null;
  let hookCreating = false;
  let hooksLoading = false;
  let hookLevel = 'all';
  let hookTiming = 'all';
  let hookEnabled = 'all';
  let hookCommandQuery = '';
  let hookNameQuery = '';
  let hookPage = 1;
  let hookPageSize = 25;
  let hookSort = 'level';
  let hookDirection = 'asc';
  let hookContextMenu = null;
  let hookToggleConfirmation = null;
  let hookToggling = false;
  let hookDeleteConfirmation = null;
  let hookDeleting = false;
  let hookEditorDialog = null;
  let hookEditorLoading = false;
  let hookEditorSaving = false;
  let hookEditorElement = null;
  let hookEditorView = null;
  let hookRunResultView = null;
  let hookRunResultElement = null;
  let hookRunning = false;
  let users = [];
  let usersTotal = 0;
  let usersPage = 1;
  let usersPageSize = 25;
  let usersLoading = false;
  let userDialog = null;
  let userDeleteConfirmation = null;
  let userContextMenu = null;
  let userPasswordAlert = null;
  let protectedAlert = null;
  const panelServices = ['dnsdock', 'panel-gateway', 'traefik'];
  const PANEL_CHANNEL = 'panel:system';
  let panelSocket = null;
  let panelReconnectTimer = null;
  let panelChannelEnabled = false;
  const pageRefresh = createRefreshCoordinator();

  $: hasRunningServices = systemServices.some((service) => service.running);
  $: hasStoppedServices = systemServices.some((service) => !service.running);
  $: queueStatus = queuePaused
    ? 'paused'
    : queueItems.some((item) => item.status === '50-error')
      ? 'error'
      : queueItems.some((item) => item.status === '40-failure')
        ? 'failure'
        : queueItems.some((item) => item.status === '20-active') ? 'active' : 'healthy';
  $: notificationBadgeLevel = notifications.some((item) => item.level === 'error')
    ? 'error'
      : notifications.some((item) => item.level === 'warn')
      ? 'warn'
      : notifications.some((item) => item.level === 'info') ? 'info' : 'debug';
  $: projectLocationOptions = projectAddOptions.locations.length
    ? projectAddOptions.locations.map((item) => ({ value: item.code, label: item.code }))
    : [{ value: '', label: 'Локации не добавлены', disabled: true }];
  $: projectLocationCollection = useListCollection({ items: projectLocationOptions });
  $: projectCloneLocationCollection = useListCollection({ items: [{ value: '', label: 'Рядом с исходным проектом' }, ...projectAddOptions.locations.map((item) => ({ value: item.code, label: item.code }))] });
  $: projectLanguageCollection = useListCollection({ items: projectAddOptions.languages.map((item) => ({ value: item.code, label: item.name })) });
  $: projectLanguageVersionCollection = useListCollection({ items: projectAddOptions.languageVersions.map((version) => ({ value: version, label: version })) });
  $: projectFrameworkCollection = useListCollection({ items: (projectAddOptions.frameworks[projectAddDialog?.language] || []).map((item) => ({ value: item.code, label: item.name })) });
  $: projectUpdateFrameworkCollection = useListCollection({ items: (projectAddOptions.frameworks[projectUpdateDialog?.language] || []).map((item) => ({ value: item.code, label: item.name })) });
  $: projectDeploymentCollection = useListCollection({ items: [{ value: '', label: 'Не использовать' }, ...projectAddOptions.deploymentScripts.map((item) => ({ value: item.code, label: item.name }))] });
  $: defaultProjectDatabaseLocation = projectAddOptions.databaseLocations.find((item) => item.default) || null;
  $: projectDatabaseLocationOptions = [
    { value: 'system', label: 'Системное расположение' },
    ...(defaultProjectDatabaseLocation ? [{ value: 'default', label: `Расположение по умолчанию (${defaultProjectDatabaseLocation.code})` }] : []),
    ...projectAddOptions.databaseLocations.map((item) => ({ value: item.code, label: item.code })),
  ];
  $: projectDatabaseLocationCollection = useListCollection({ items: projectDatabaseLocationOptions });
  $: backupStorageOptions = [{ value: '', label: 'Папка проекта' }, ...backupLocations.filter((item) => item.code && item.path).map((item) => ({ value: item.code, label: item.code }))];
  $: backupStorageCollection = useListCollection({ items: backupStorageOptions });
  $: backupLocationFilterOptions = [{ value: 'all', label: 'Все расположения' }, { value: 'project', label: 'Папка проекта' }, ...backupLocations.filter((item) => item.code && item.path).map((item) => ({ value: item.code, label: item.code }))];
  $: backupLocationFilterCollection = useListCollection({ items: backupLocationFilterOptions });
  $: backupStrategyFilterOptions = [{ value: 'all', label: 'Любая стратегия' }, { value: 'none', label: 'Без стратегии' }, ...backupFileStrategies.filter((item) => item.code && item.name).map((item) => ({ value: item.code, label: item.name }))];
  $: backupStrategyFilterCollection = useListCollection({ items: backupStrategyFilterOptions });
  $: backupCreateStrategyOptions = [{ value: '', label: 'Без стратегии' }, ...backupFileStrategies.filter((item) => item.code && item.name).map((item) => ({ value: item.code, label: item.name }))];
  $: backupCreateStrategyCollection = useListCollection({ items: backupCreateStrategyOptions });
  $: hookProjectCollection = useListCollection({ items: [{ value: '', label: 'Проект не выбран' }, ...projects.map((project) => ({ value: project.name, label: project.name }))] });
  $: hookCommandFilterOptions = [{ value: 'all', label: 'Все команды' }, ...hookCommands.map((value) => ({ value, label: value }))];
  $: hookCommandCollection = useListCollection({ items: hookCommandFilterOptions });
  $: selectedBackupCreateStrategy = backupFileStrategies.find((item) => item.code === backupCreateDialog?.strategy) || null;
  $: selectedDeploymentScript = projectAddOptions.deploymentScripts.find((item) => item.code === projectAddDialog?.deploymentScript) || null;
  $: filteredScheduleItems = scheduleItems.map((item, index) => ({ ...item, enabled: item.enabled !== false, index })).filter((item) => item.command.toLocaleLowerCase().includes(scheduleQuery.trim().toLocaleLowerCase()) && (scheduleStatus === 'all' || item.enabled === (scheduleStatus === 'enabled')));
  $: schedulePageCount = Math.max(1, Math.ceil(filteredScheduleItems.length / schedulePageSize));
  $: if (schedulePage > schedulePageCount) schedulePage = schedulePageCount;
  $: pagedScheduleItems = filteredScheduleItems.slice((schedulePage - 1) * schedulePageSize, schedulePage * schedulePageSize);
  $: isHookLog = specificSelections(logType).length === 1 && specificSelections(logType)[0] === 'hook';
  $: filteredHooks = hooks.filter((hook) => {
    const commandQuery = hookCommandQuery.trim().toLocaleLowerCase();
    const nameQuery = hookNameQuery.trim().toLocaleLowerCase();
    return (hookLevel === 'all' || hook.level === hookLevel)
      && (hookTiming === 'all' || hook.timing === hookTiming)
      && (hookEnabled === 'all' || hook.enabled === (hookEnabled === 'enabled'))
      && (!commandQuery || hook.command.toLocaleLowerCase().includes(commandQuery))
      && (!nameQuery || hook.hook.toLocaleLowerCase().includes(nameQuery));
  });
  $: sortedHooks = [...filteredHooks].sort((left, right) => {
    const result = compareHookSortValue(left[hookSort], right[hookSort]);
    return hookDirection === 'asc' ? result : -result;
  });
  $: hookPageCount = Math.max(1, Math.ceil(filteredHooks.length / hookPageSize));
  $: if (hookPage > hookPageCount) hookPage = hookPageCount;
  $: pagedHooks = sortedHooks.slice((hookPage - 1) * hookPageSize, hookPage * hookPageSize);

  $: selectedProject = projects.find((project) => project.name === selectedProjectName) || null;
  $: if (selectedProject && selectedProject.name !== notesProjectName) {
    notesProjectName = selectedProject.name;
    noteTags = [...selectedProject.tags];
    noteTagInput = '';
    noteDescription = selectedProject.description;
  }
  $: filteredProjects = projects.filter((project) => {
    const matchesName = project.name.toLocaleLowerCase().includes(projectQuery.trim().toLocaleLowerCase());
    const tags = [project.language?.code || 'no-language', project.framework?.code || 'no-framework', ...project.tags];
    return matchesName && projectTags.every((tag) => tags.includes(tag));
  });

  function addProjectTag(tag) {
    if (!projectTags.includes(tag)) projectTags = [...projectTags, tag];
  }

  function removeProjectTag(tag) {
    projectTags = projectTags.filter((item) => item !== tag);
  }

  function projectHash(name = '', tab = 'info') {
    return name ? `#/projects/${encodeURIComponent(name)}/${tab}` : '#/projects';
  }

  function navigateToProject(name, tab = 'info') {
    const hash = projectHash(name, tab);
    if (window.location.hash === hash) {
      selectedProjectName = name;
      projectDetailTab = tab;
    } else {
      window.location.hash = hash;
    }
  }

  function specificSelections(values) {
    return values.filter((value) => value !== 'all');
  }

  function logSelectionLabel(items, selected) {
    const labels = new Map(items.map((item) => [item.value, item.label]));
    return selected.map((value) => labels.get(value)).filter(Boolean).join(', ');
  }

  function normalizeFilterSelection(previous, next) {
    if (next.includes('all')) {
      return previous.includes('all') ? next.filter((value) => value !== 'all') : ['all'];
    }
    return next;
  }

  function appendFilterValues(parameters, name, selected) {
    for (const value of specificSelections(selected)) parameters.append(name, value);
  }

  function journalFilterHash(projectJournal = false) {
    const path = projectJournal ? projectHash(selectedProjectName, 'journal') : '#/journal';
    const parameters = new URLSearchParams();
    parameters.set('type', specificSelections(logType)[0] || 'queue');
    if (!projectJournal) appendFilterValues(parameters, 'project', logProject);
    appendFilterValues(parameters, 'status', logStatus);
    appendFilterValues(parameters, 'level', logLevel);
    appendFilterValues(parameters, 'context', logContext);
    if (logQueueItem) parameters.set('queue_item', logQueueItem);
    if (logItemCode) parameters.set('item_code', logItemCode);
    if (logTaskCode) parameters.set('task_code', logTaskCode);
    if (logHook) parameters.set('hook', logHook);
    if (logCommand) parameters.set('command', logCommand);
    if (logTiming) parameters.set('timing', logTiming);
    if (logHookLevel) parameters.set('hookLevel', logHookLevel);
    const query = parameters.toString();
    return query ? `${path}?${query}` : path;
  }

  function syncJournalFilters(projectJournal = activeSection === 'projects' && projectDetailTab === 'journal') {
    const hash = journalFilterHash(projectJournal);
    if (window.location.hash !== hash) history.replaceState(history.state, '', hash);
  }

  function applyJournalFilters(projectJournal) {
    clearTimeout(logFilterTimer);
    const query = window.location.hash.split('?', 2)[1] || '';
    const parameters = new URLSearchParams(query);
    const values = (name) => parameters.getAll(name);
    const scalar = (name) => values(name).length === 1 ? values(name)[0] : '';
    const validText = (value) => value.length <= 500 && !/[\u0000-\u001f\u007f]/.test(value);
    const validSelections = (name, items, fallback = ['all']) => {
      const allowed = new Set(items.map((item) => item.value));
      const selected = values(name).filter((value) => allowed.has(value) && value !== 'all');
      return selected.length ? [...new Set(selected)] : fallback;
    };
    const queueItem = scalar('queue_item');
    const itemCode = scalar('item_code');
    const taskCode = scalar('task_code');
    const hook = scalar('hook');
    const command = scalar('command');
    const timing = scalar('timing');
    const hookLevel = scalar('hookLevel');

    logType = validSelections('type', logTypes, ['queue']);
    logProject = projectJournal ? ['all'] : validSelections('project', [{ value: 'all' }, ...projects.map((item) => ({ value: item.name }))]);
    logStatus = validSelections('status', logStatuses);
    logLevel = validSelections('level', logLevels);
    logContext = validSelections('context', logContexts);
    logQueueItem = queueItem && validText(queueItem) ? queueItem : '';
    logItemCode = itemCode && validText(itemCode) ? itemCode : '';
    logTaskCode = taskCode && validText(taskCode) ? taskCode : '';
    logHook = hook && validText(hook) ? hook : '';
    logCommand = command && validText(command) ? command : '';
    logTiming = ['before', 'after'].includes(timing) ? timing : '';
    logHookLevel = hookLevel === 'command' ? hookLevel : '';
    logPage = 1;
    syncJournalFilters(projectJournal);
  }

  function applyHashNavigation() {
    if (!authenticated) return;
    const [hashPath] = window.location.hash.split('?', 1);
    const segments = hashPath.replace(/^#\/?/, '').split('/').filter(Boolean);
    if (segments[0] === 'journal') {
      activeSection = 'logs';
      selectedProjectName = '';
      applyJournalFilters(false);
      loadLogs();
      return;
    }
    if (segments.length === 2 && segments[0] === 'settings' && ['projects', 'backups', 'users', 'hooks', 'security'].includes(segments[1])) {
      activeSection = 'settings';
      settingsTab = segments[1];
      selectedProjectName = '';
      if (settingsTab === 'projects') loadProjectsSettings();
      else if (settingsTab === 'backups') loadBackupsSettings();
      else if (settingsTab === 'users') loadUsersSettings();
      else if (settingsTab === 'hooks') loadHooksSettings();
      else loadSecuritySettings();
      return;
    }
    activeSection = 'projects';
    if (segments[0] !== 'projects') {
      navigateToProject('', 'info');
      return;
    }
    let projectName = '';
    try {
      projectName = segments[1] ? decodeURIComponent(segments[1]) : '';
    } catch {
      navigateToProject('', 'info');
      return;
    }
    if (!projectName) {
      selectedProjectName = '';
      projectDetailTab = 'info';
      if (window.location.hash !== projectHash()) navigateToProject('', 'info');
      return;
    }
    const tab = projectDetailTabs.includes(segments[2]) ? segments[2] : 'info';
    selectedProjectName = projectName;
    projectDetailTab = tab;
    if (tab === 'journal') {
      applyJournalFilters(true);
      loadLogs();
    }
    if (tab === 'backups') { loadBackupsSettings(); loadProjectBackups(); }
    if (tab === 'scheduler') loadSchedule();
    if (segments.length !== 3 || !projectDetailTabs.includes(segments[2])) navigateToProject(projectName, tab);
  }

  async function loadSchedule() {
    if (!selectedProjectName) return;
    scheduleLoading = true;
    try { scheduleItems = (await getProjectSchedule(api, selectedProjectName)).items; }
    catch (requestError) { errorTitle = 'Не удалось загрузить расписание'; error = requestError instanceof Error ? requestError.message : errorTitle; }
    finally { scheduleLoading = false; }
  }

  function openScheduleDialog() {
    scheduleDialog = { index: null, enabled: true, cron: ['*', '*', '*', '*', '*'], command: '', workingDirectory: '' };
  }

  function openScheduleContextMenu(event, item) {
    if (event.ctrlKey) { scheduleContextMenu = null; return; }
    event.preventDefault();
    event.stopPropagation();
    const bounds = event.currentTarget.getBoundingClientRect();
    const x = 'clientX' in event && event.clientX > 0 ? event.clientX : bounds.right;
    const y = 'clientY' in event && event.clientY > 0 ? event.clientY : bounds.bottom;
    scheduleContextMenu = { item, x: Math.max(8, Math.min(x, window.innerWidth - 180)), y: Math.max(8, Math.min(y, window.innerHeight - 152)) };
  }

  function editScheduleItem(item) {
    scheduleDialog = { index: item.index, enabled: item.enabled !== false, cron: item.schedule.split(/\s+/), command: item.command, workingDirectory: item.workingDirectory || '' };
    scheduleContextMenu = null;
  }

  function applyCronTemplate(schedule) {
    scheduleDialog = { ...scheduleDialog, cron: schedule.split(' ') };
  }

  async function saveScheduleItem() {
    if (!scheduleDialog) return;
    scheduleSaving = true;
    try {
      const item = { enabled: scheduleDialog.enabled, schedule: scheduleDialog.cron.join(' '), command: scheduleDialog.command.trim(), workingDirectory: scheduleDialog.workingDirectory.trim() };
      const data = scheduleDialog.index === null
        ? await addProjectSchedule(api, selectedProjectName, item)
        : await updateProjectSchedule(api, selectedProjectName, scheduleDialog.index, item);
      scheduleItems = data.items;
      scheduleDialog = null;
    } catch (requestError) { errorTitle = 'Не удалось сохранить команду'; error = requestError instanceof Error ? requestError.message : errorTitle; }
    finally { scheduleSaving = false; }
  }

  async function deleteScheduleItem(item) {
    scheduleContextMenu = null;
    scheduleDeleting = true;
    try {
      scheduleItems = (await deleteProjectSchedule(api, selectedProjectName, item.index)).items;
      scheduleDeleteConfirmation = null;
    }
    catch (requestError) { errorTitle = 'Не удалось удалить команду'; error = requestError instanceof Error ? requestError.message : errorTitle; }
    finally { scheduleDeleting = false; }
  }

  async function toggleScheduleItem(item) {
    scheduleContextMenu = null;
    scheduleToggling = true;
    try {
      const changes = { enabled: !item.enabled, schedule: item.schedule, command: item.command, workingDirectory: item.workingDirectory || '' };
      scheduleItems = (await updateProjectSchedule(api, selectedProjectName, item.index, changes)).items;
      scheduleToggleConfirmation = null;
    } catch (requestError) { errorTitle = `Не удалось ${item.enabled ? 'выключить' : 'включить'} команду`; error = requestError instanceof Error ? requestError.message : errorTitle; }
    finally { scheduleToggling = false; }
  }


  function hookEditorExtensions(lines, onChange) {
    return [
      lineNumbers(), highlightActiveLineGutter(), cmHistory(), StreamLanguage.define(shell), highlightActiveLine(),
      keymap.of([...defaultKeymap, ...historyKeymap]),
      EditorView.lineWrapping,
      EditorView.updateListener.of((update) => { if (update.docChanged) onChange(update.state.doc.toString()); }),
      EditorView.theme({ '&': { height: `${lines * 1.45 + .7}rem` }, '.cm-scroller': { overflow: 'auto' } }),
    ];
  }

  function editorThemeExtension() {
    const [, , extension] = editorThemes.find(([value]) => value === editorTheme) || editorThemes[0];
    return extension;
  }

  function createHookEditor(node, doc, lines, onChange) {
    return new EditorView({ parent: node, state: EditorState.create({ doc, extensions: [...hookEditorExtensions(lines, onChange), editorThemeExtension()] }) });
  }

  function setEditorTheme(value) {
    if (!editorThemes.some(([themeValue]) => themeValue === value)) return;
    editorTheme = value;
    localStorage.setItem(EDITOR_THEME_KEY, value);
    editorThemeOpen = false;
    setTimeout(() => { rebuildHookEditor(); rebuildHookRunResultEditor(); }, 0);
  }

  function mountHookEditor(node) {
    hookEditorElement = node;
    rebuildHookEditor();
    return { destroy() { hookEditorElement = null; hookEditorView?.destroy(); hookEditorView = null; } };
  }

  function rebuildHookEditor() {
    if (!hookEditorElement || !hookEditorDialog) return;
    hookEditorView?.destroy();
    hookEditorView = createHookEditor(hookEditorElement, hookEditorDialog.content, hookEditorDialog.runResult ? 5 : 10, (content) => {
      if (hookEditorDialog) hookEditorDialog = { ...hookEditorDialog, content };
    });
  }

  function mountHookRunResultEditor(node) {
    hookRunResultElement = node;
    rebuildHookRunResultEditor();
    return { destroy() { hookRunResultElement = null; hookRunResultView?.destroy(); hookRunResultView = null; } };
  }

  function rebuildHookRunResultEditor() {
    if (!hookRunResultElement || !hookEditorDialog?.runResult) return;
    hookRunResultView?.destroy();
    hookRunResultView = createHookEditor(hookRunResultElement, hookEditorDialog.runResult, 5, () => {});
  }

  function setHookWorkingDirectory(projectName) {
    const project = projects.find((item) => item.name === projectName);
    if (hookEditorDialog) hookEditorDialog = { ...hookEditorDialog, project: project?.name || '', workingDirectory: project?.root || '' };
  }

  async function loadHooksSettings() {
    if (!authenticated) return;
    hooksLoading = true;
    try {
      const data = await getHooksSettings(api);
      hooks = data.hooks;
      hookCommands = Array.isArray(data.commands) ? data.commands : [];
    }
    catch (requestError) { errorTitle = 'Не удалось загрузить хуки'; error = requestError instanceof Error ? requestError.message : errorTitle; }
    finally { hooksLoading = false; }
  }

  function openHookCreateDialog() {
    hookCreateDialog = { name: '', enabled: false, level: 'command', command: hookCommands[0] || '', timing: 'before' };
  }

  async function createHookItem() {
    if (!hookCreateDialog) return;
    hookCreating = true;
    try {
      const data = await createHookSettings(api, hookCreateDialog);
      const hook = data.hook;
      hooks = [...hooks, hook];
      hookCreateDialog = null;
      await editHookItem(hook);
    } catch (requestError) {
      errorTitle = 'Не удалось добавить хук';
      error = requestError instanceof Error ? requestError.message : errorTitle;
    } finally { hookCreating = false; }
  }

  function changeHookFilter(field, value) {
    if (field === 'level') hookLevel = value;
    else if (field === 'timing') hookTiming = value;
    else if (field === 'enabled') hookEnabled = value;
    else if (field === 'command') hookCommandQuery = value;
    else if (field === 'hook') hookNameQuery = value;
    hookPage = 1;
  }

  function sortHooks(field) {
    if (hookSort === field) hookDirection = hookDirection === 'asc' ? 'desc' : 'asc';
    else { hookSort = field; hookDirection = 'asc'; }
    hookPage = 1;
  }

  function compareHookSortValue(left, right) {
    if (typeof left === 'boolean' || typeof right === 'boolean') {
      return Number(Boolean(left)) - Number(Boolean(right));
    }
    return String(left ?? '').localeCompare(String(right ?? ''), 'ru', { numeric: true });
  }

  function openHookContextMenu(event, hook) {
    if (event.ctrlKey) { hookContextMenu = null; return; }
    event.preventDefault();
    event.stopPropagation();
    const bounds = event.currentTarget.getBoundingClientRect();
    const x = 'clientX' in event && event.clientX > 0 ? event.clientX : bounds.right;
    const y = 'clientY' in event && event.clientY > 0 ? event.clientY : bounds.bottom;
    hookContextMenu = { hook, x: Math.max(8, Math.min(x, window.innerWidth - 180)), y: Math.max(8, Math.min(y, window.innerHeight - 120)) };
  }


  async function editHookItem(hook) {
    hookContextMenu = null;
    hookEditorDialog = { hook, content: '', name: hook.hook.replace(/^\.+/, ''), enabled: hook.enabled, command: hook.command, timing: hook.timing, profile: `hook:command ${hook.command}:${hook.timing}`, workingDirectory: '', project: '', runResult: '' };
    hookEditorLoading = true;
    try {
      const data = await getHookContent(api, hook.id);
      hookEditorDialog = { ...hookEditorDialog, hook, content: typeof data.content === 'string' ? data.content : '' };
      setTimeout(rebuildHookEditor, 0);
    } catch (requestError) {
      hookEditorDialog = null;
      errorTitle = 'Не удалось открыть хук';
      error = requestError instanceof Error ? requestError.message : errorTitle;
    } finally { hookEditorLoading = false; }
  }

  async function runHookEditor() {
    if (!hookEditorDialog) return;
    hookRunning = true;
    try {
      const profile = hookEditorDialog.profile;
      const workingDirectory = hookEditorDialog.workingDirectory;
      const result = await runHookSettings(api, hookEditorDialog.hook.id, profile, workingDirectory);
      const output = `Рабочая директория: ${result.workingDirectory || workingDirectory || '—'}\nКод возврата: ${Number(result.exitCode)}\n\n[stdout]\n${result.stdout || ''}\n\n[stderr]\n${result.stderr || ''}`;
      hookEditorDialog = { ...hookEditorDialog, profile, workingDirectory, runResult: output };
      setTimeout(() => { rebuildHookEditor(); rebuildHookRunResultEditor(); }, 0);
    } catch (requestError) {
      errorTitle = 'Не удалось выполнить хук';
      error = requestError instanceof Error ? requestError.message : errorTitle;
    } finally { hookRunning = false; }
  }

  async function saveHookEditor() {
    if (!hookEditorDialog) return;
    hookEditorSaving = true;
    try {
      const content = hookEditorView ? hookEditorView.state.doc.toString() : hookEditorDialog.content;
      await saveHookContent(api, hookEditorDialog.hook.id, { content, name: hookEditorDialog.name, enabled: hookEditorDialog.enabled, command: hookEditorDialog.command, timing: hookEditorDialog.timing });
      hookEditorDialog = null;
      await loadHooksSettings();
    } catch (requestError) {
      errorTitle = 'Не удалось сохранить хук';
      error = requestError instanceof Error ? requestError.message : errorTitle;
    } finally { hookEditorSaving = false; }
  }

  async function toggleHookItem(hook) {
    hookContextMenu = null;
    hookToggling = true;
    try {
      hooks = (await toggleHookSettings(api, hook.id)).hooks;
      hookToggleConfirmation = null;
    } catch (requestError) { errorTitle = `Не удалось ${hook.enabled ? 'выключить' : 'включить'} хук`; error = requestError instanceof Error ? requestError.message : errorTitle; }
    finally { hookToggling = false; }
  }

  async function deleteHookItem(hook) {
    hookContextMenu = null;
    hookDeleting = true;
    try {
      hooks = (await deleteHookSettings(api, hook.id)).hooks;
      hookDeleteConfirmation = null;
    } catch (requestError) { errorTitle = 'Не удалось удалить хук'; error = requestError instanceof Error ? requestError.message : errorTitle; }
    finally { hookDeleting = false; }
  }

  async function loadProjectBackups() {
    if (!authenticated || !selectedProjectName) return;
    const requestId = ++backupRequestId;
    backupsLoading = true;
    projectsError = '';
    try {
      const data = await getProjectBackups(api, selectedProjectName, { page: String(backupPage), pageSize: String(backupPageSize), name: backupName, composition: backupComposition, database: backupDatabase, strategy: backupStrategy, location: backupLocation, dateFrom: backupDateFrom, dateTo: backupDateTo, sort: backupSort, direction: backupDirection });
      if (requestId !== backupRequestId) return;
      backupItems = Array.isArray(data.items) ? data.items : [];
      backupTotal = Number(data.total) || 0;
    } catch (cause) {
      if (requestId === backupRequestId) projectsError = cause instanceof Error ? cause.message : 'Не удалось загрузить бэкапы.';
    } finally {
      if (requestId === backupRequestId) backupsLoading = false;
    }
  }

  function refreshProjectBackups() {
    return Promise.all([loadBackupsSettings(), loadProjectBackups()]);
  }

  function changeBackupFilter(field, value) {
    if (field === 'name') backupName = value;
    else if (field === 'composition') backupComposition = value;
    else if (field === 'database') backupDatabase = value;
    else if (field === 'strategy') backupStrategy = value;
    else if (field === 'location') backupLocation = value;
    else if (field === 'dateFrom') backupDateFrom = value;
    else backupDateTo = value;
    backupPage = 1;
    clearTimeout(backupFilterTimer);
    backupFilterTimer = setTimeout(loadProjectBackups, 250);
  }

  function sortBackups(field) {
    backupDirection = backupSort === field && backupDirection === 'asc' ? 'desc' : 'asc';
    backupSort = field;
    backupPage = 1;
    loadProjectBackups();
  }

  function openBackupContextMenu(event, backup) {
    if (event.ctrlKey) { backupContextMenu = null; return; }
    event.preventDefault();
    event.stopPropagation();
    const bounds = event.currentTarget.getBoundingClientRect();
    const x = 'clientX' in event && event.clientX > 0 ? event.clientX : bounds.right;
    const y = 'clientY' in event && event.clientY > 0 ? event.clientY : bounds.bottom;
    backupContextMenu = {
      backup,
      x: Math.max(8, Math.min(x, window.innerWidth - 184)),
      y: Math.max(8, Math.min(y, window.innerHeight - 104)),
    };
  }

  function openBackupRestoreDialog(backup) {
    backupContextMenu = null;
    if (selectedProject?.protected) {
      protectedAlert = selectedProject;
      return;
    }
    backupRestoreConfirmation = { ...backup, restoreDatabases: [...(backup.databaseCodes || [])], restoreFiles: backup.hasFiles === true && backup.filesValid !== false, force: true, wipe: false };
  }

  function toggleRestoreDatabase(database, checked) {
    if (!backupRestoreConfirmation) return;
    const restoreDatabases = checked
      ? [...backupRestoreConfirmation.restoreDatabases, database]
      : backupRestoreConfirmation.restoreDatabases.filter((item) => item !== database);
    backupRestoreConfirmation = { ...backupRestoreConfirmation, restoreDatabases };
  }

  function openBackupDeleteDialog(backup) {
    backupContextMenu = null;
    if (selectedProject?.protected) {
      protectedAlert = selectedProject;
      return;
    }
    backupDeleteConfirmation = { ...backup, deleteDatabases: [...(backup.databaseCodes || [])], deleteFiles: backup.hasFiles === true };
  }

  function toggleDeleteDatabase(database, checked) {
    if (!backupDeleteConfirmation) return;
    const deleteDatabases = checked
      ? [...backupDeleteConfirmation.deleteDatabases, database]
      : backupDeleteConfirmation.deleteDatabases.filter((item) => item !== database);
    backupDeleteConfirmation = { ...backupDeleteConfirmation, deleteDatabases };
  }

  function openBackupCreateDialog() {
    backupCreateDialog = { database: true, files: false, mysql: true, postgres: false, strategy: '', compress: '', chunkSize: '', chunkCount: '', location: '', comment: '' };
    loadBackupsSettings();
  }

  async function createBackup() {
    if (!backupCreateDialog || !selectedProjectName || (!backupCreateDialog.database && !backupCreateDialog.files) || (backupCreateDialog.database && !backupCreateDialog.mysql && !backupCreateDialog.postgres)) return;
    backupCreatePending = true;
    try {
      await createProjectBackup(api, selectedProjectName, {
        database: backupCreateDialog.database,
        files: backupCreateDialog.files,
        mysql: backupCreateDialog.mysql,
        postgres: backupCreateDialog.postgres,
        location: backupCreateDialog.location,
        strategy: backupCreateDialog.strategy,
        compress: backupCreateDialog.compress,
        chunkSize: backupCreateDialog.chunkSize,
        chunkCount: backupCreateDialog.chunkCount,
        comment: backupCreateDialog.comment,
      });
      backupCreateDialog = null;
      notifyQueuedOperation(`Создание бэкапа проекта «${selectedProjectName}»`);
    } catch (cause) {
      errorTitle = 'Не удалось создать бэкап';
      error = cause instanceof Error ? cause.message : 'Не удалось поставить создание бэкапа в очередь.';
      errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
    } finally {
      backupCreatePending = false;
    }
  }

  function openBackupCommentDialog(backup) {
    backupContextMenu = null;
    backupCommentDialog = { ...backup, comment: backup.comment || '' };
  }

  async function saveBackupComment() {
    if (!backupCommentDialog || !selectedProjectName) return;
    backupCommentPending = true;
    try {
      await updateProjectBackupComment(api, selectedProjectName, backupCommentDialog.name, { location: backupCommentDialog.location, comment: backupCommentDialog.comment });
      backupCommentDialog = null;
      await loadProjectBackups();
    } catch (cause) {
      errorTitle = 'Не удалось изменить бэкап';
      error = cause instanceof Error ? cause.message : 'Не удалось сохранить комментарий.';
      errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
    } finally {
      backupCommentPending = false;
    }
  }

  async function restoreBackup() {
    if (!backupRestoreConfirmation || !selectedProjectName) return;
    const backup = backupRestoreConfirmation;
    backupRestoreConfirmation = null;
    backupRestorePending = true;
    try {
      await restoreProjectBackup(api, selectedProjectName, backup.name, { database: backup.restoreDatabases[0] || '', databases: backup.restoreDatabases, location: backup.location, files: backup.restoreFiles, force: backup.force, wipe: backup.wipe });
      notifyQueuedOperation(`Восстановление бэкапа «${backup.name}»`);
    } catch (cause) {
      errorTitle = 'Не удалось восстановить бэкап';
      error = cause instanceof Error ? cause.message : 'Не удалось поставить восстановление бэкапа в очередь.';
      errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
    } finally {
      backupRestorePending = false;
    }
  }

  async function deleteBackup() {
    if (!backupDeleteConfirmation || !selectedProjectName) return;
    const backup = backupDeleteConfirmation;
    backupDeleteConfirmation = null;
    backupDeletePending = true;
    try {
      await deleteProjectBackup(api, selectedProjectName, backup.name, { database: backup.deleteDatabases[0] || '', databases: backup.deleteDatabases, files: backup.deleteFiles, location: backup.location });
      notifyQueuedOperation(`Удаление бэкапа «${backup.name}»`);
    } catch (cause) {
      errorTitle = 'Не удалось удалить бэкап';
      error = cause instanceof Error ? cause.message : 'Не удалось поставить удаление бэкапа в очередь.';
      errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
    } finally {
      backupDeletePending = false;
    }
  }

  function formatBytes(value) {
    const bytes = Number(value) || 0;
    if (bytes < 1024) return `${bytes} Б`;
    const units = ['КБ', 'МБ', 'ГБ', 'ТБ'];
    const unit = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)) - 1, units.length - 1);
    return `${(bytes / (1024 ** (unit + 1))).toLocaleString('ru-RU', { maximumFractionDigits: 1 })} ${units[unit]}`;
  }

  function formatVolumeCount(value) {
    const count = Number(value) || 1;
    const remainder100 = count % 100;
    const remainder10 = count % 10;
    const noun = remainder100 >= 11 && remainder100 <= 14 ? 'томов' : remainder10 === 1 ? 'том' : remainder10 >= 2 && remainder10 <= 4 ? 'тома' : 'томов';
    return `${count} ${noun}`;
  }

  function formatVolumeSuffix(value) {
    return Number(value) > 1 ? ` (${formatVolumeCount(value)})` : '';
  }

  function formatBackupSize(backup) {
    const parts = Array.isArray(backup?.sizeParts) ? backup.sizeParts : [];
    if (parts.length === 0) return formatBytes(backup?.size);
    if (parts.length === 1) {
      const part = parts[0];
      return part.type === 'files' ? `${formatBytes(part.size)}${formatVolumeSuffix(part.volumeCount)}` : formatBytes(part.size);
    }
    return parts.map((part) => part.type === 'files'
      ? `Файлы: ${formatBytes(part.size)}${formatVolumeSuffix(part.volumeCount)}`
      : `${part.name}: ${formatBytes(part.size)}`).join(', ');
  }

  async function loadLogs() {
    if (!authenticated) return;
    const requestId = ++logRequestId;
    logsLoading = true;
    projectsError = '';
    try {
      const projectJournal = activeSection === 'projects' && projectDetailTab === 'journal';
      const data = await getLogs(api, {
        page: String(logPage), pageSize: String(logPageSize), sort: logSort, direction: logDirection, type: specificSelections(logType)[0] || 'queue',
        ...(projectJournal ? { project: selectedProjectName } : specificSelections(logProject).length ? { project: specificSelections(logProject).join(',') } : {}),
        ...(specificSelections(logStatus).length ? { status: specificSelections(logStatus).join(',') } : {}),
        ...(specificSelections(logLevel).length ? { level: specificSelections(logLevel).join(',') } : {}),
        ...(specificSelections(logContext).length ? { context: specificSelections(logContext).join(',') } : {}),
        ...(logQueueItem ? { queueItem: logQueueItem } : {}),
        ...(logItemCode ? { itemCode: logItemCode } : {}),
        ...(logTaskCode ? { taskCode: logTaskCode } : {}),
        ...(logHook ? { hook: logHook } : {}),
        ...(logCommand ? { command: logCommand } : {}),
        ...(logTiming ? { timing: logTiming } : {}),
        ...(logHookLevel ? { hookLevel: logHookLevel } : {}),
      });
      if (requestId !== logRequestId) return;
      logItems = Array.isArray(data.items) ? data.items : [];
      logTotal = Number(data.total) || 0;
      logProjects = Array.isArray(data.projects) ? data.projects : [];
      logProjectCollection = useListCollection({ items: [{ value: 'all', label: 'Все проекты' }, ...logProjects.map((value) => ({ value, label: value }))] });
      if (!projectJournal && specificSelections(logProject).some((project) => !logProjects.includes(project))) {
        logProject = specificSelections(logProject).filter((project) => logProjects.includes(project));
        syncJournalFilters(false);
        void loadLogs();
        return;
      }
    } catch (cause) {
      if (requestId === logRequestId) {
        projectsError = cause instanceof Error ? cause.message : 'Не удалось загрузить журнал.';
      }
    } finally {
      if (requestId === logRequestId) logsLoading = false;
    }
  }

  async function loadProjectsSettings() {
    if (!authenticated || projectSettingsLoading) return;
    projectSettingsLoading = true;
    try {
      const data = await getProjectsSettings(api);
      projectLocations = Array.isArray(data.locations) && data.locations.length
        ? data.locations.map((location) => ({ path: location.path, code: location.code || '', default: location.default === true }))
        : [{ path: '', code: '', default: true }];
      projectDatabaseLocations = Array.isArray(data.databaseLocations) && data.databaseLocations.length
        ? data.databaseLocations.map((location) => ({ path: location.path, code: location.code || '', default: location.default === true }))
        : [{ path: '', code: '', default: false }];
    } catch (cause) {
      errorTitle = 'Не удалось загрузить настройки';
      error = cause instanceof Error ? cause.message : 'Не удалось загрузить расположения проектов.';
      errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
    } finally {
      projectSettingsLoading = false;
    }
  }

  async function loadBackupsSettings() {
    if (!authenticated || backupSettingsLoading) return;
    backupSettingsLoading = true;
    try {
      const data = await getBackupsSettings(api);
      backupLocations = Array.isArray(data.locations) && data.locations.length
        ? data.locations.map((location) => ({ path: location.path, code: location.code || '', default: location.default === true }))
        : [{ path: '', code: '', default: true }];
      backupFileStrategies = Array.isArray(data.fileStrategies)
        && data.fileStrategies.length
        ? data.fileStrategies.map((strategy) => ({ name: strategy.name, code: strategy.code || '', include: strategy.include || [], exclude: strategy.exclude || [], databaseInclude: strategy.databaseInclude || [], databaseExclude: strategy.databaseExclude || [] }))
        : [{ name: '', code: '', include: [], exclude: [], databaseInclude: [], databaseExclude: [] }];
    } catch (cause) {
      errorTitle = 'Не удалось загрузить настройки';
      error = cause instanceof Error ? cause.message : 'Не удалось загрузить расположения бэкапов.';
      errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
    } finally {
      backupSettingsLoading = false;
    }
  }

  async function loadUsersSettings() {
    if (!authenticated || usersLoading) return;
    usersLoading = true;
    try {
      const data = await getUsersSettings(api, usersPage, usersPageSize);
      users = data.users;
      usersTotal = data.total;
      usersPage = data.page;
    } catch (cause) {
      errorTitle = 'Не удалось загрузить пользователей';
      error = cause instanceof Error ? cause.message : 'Не удалось загрузить пользователей.';
    } finally {
      usersLoading = false;
    }
  }

  function openUserContextMenu(event, user) {
    if (event.ctrlKey) { userContextMenu = null; return; }
    event.preventDefault();
    event.stopPropagation();
    const bounds = event.currentTarget.getBoundingClientRect();
    const x = 'clientX' in event && event.clientX > 0 ? event.clientX : bounds.right;
    const y = 'clientY' in event && event.clientY > 0 ? event.clientY : bounds.bottom;
    userContextMenu = { user, x: Math.max(8, Math.min(x, window.innerWidth - 180)), y: Math.max(8, Math.min(y, window.innerHeight - 120)) };
  }

  async function saveUser() {
    if (!userDialog) return;
    try {
      if (userDialog.create) {
        const login = userDialog.login.trim().toLocaleLowerCase();
        const data = await createPanelUser(api, userDialog.login, userDialog.comments);
        userDialog = null;
        userPasswordAlert = { password: data.password, login, created: true, logout: false, copied: false };
      } else {
        await updatePanelUser(api, userDialog.login, userDialog.comments);
        userDialog = null;
      }
      await loadUsersSettings();
    } catch (cause) { errorTitle = 'Не удалось сохранить пользователя'; error = cause instanceof Error ? cause.message : 'Не удалось сохранить пользователя.'; }
  }

  async function rotateUserPassword(user) {
    userDialog = null;
    try {
      const data = await rotatePanelUserPassword(api, user.login);
      userPasswordAlert = { password: data.password, login: user.login, created: false, logout: data.logout === true, copied: false };
    } catch (cause) { errorTitle = 'Не удалось изменить пароль'; error = cause instanceof Error ? cause.message : 'Не удалось изменить пароль.'; }
  }

  async function copyGeneratedPassword() {
    if (!userPasswordAlert) return;
    try {
      await navigator.clipboard.writeText(userPasswordAlert.password);
      userPasswordAlert = { ...userPasswordAlert, copied: true };
    } catch {
      errorTitle = 'Не удалось скопировать пароль';
      error = 'Скопируйте пароль вручную.';
    }
  }

  async function confirmDeleteUser() {
    const user = userDeleteConfirmation;
    userDeleteConfirmation = null;
    if (!user) return;
    try {
      const data = await deletePanelUser(api, user.login);
      if (data.logout) logout(); else await loadUsersSettings();
    } catch (cause) { errorTitle = 'Не удалось удалить пользователя'; error = cause instanceof Error ? cause.message : 'Не удалось удалить пользователя.'; }
  }

  function updateProjectLocation(index, path) {
    projectLocations = projectLocations.map((location, itemIndex) => itemIndex === index ? { ...location, path } : location);
  }

  function updateProjectLocationCode(index, code) {
    projectLocations = projectLocations.map((location, itemIndex) => itemIndex === index ? { ...location, code } : location);
  }

  function addProjectLocation() {
    projectLocations = [...projectLocations, { path: '', code: '', default: false }];
  }

  function removeProjectLocation(index) {
    const wasDefault = projectLocations[index].default;
    projectLocations = projectLocations.filter((_, itemIndex) => itemIndex !== index);
    if (wasDefault && projectLocations.length) projectLocations = projectLocations.map((location, itemIndex) => ({ ...location, default: itemIndex === 0 }));
  }

  function setDefaultProjectLocation(index) {
    projectLocations = projectLocations.map((location, itemIndex) => ({ ...location, default: itemIndex === index }));
  }

  function updateProjectDatabaseLocation(index, field, value) {
    projectDatabaseLocations = projectDatabaseLocations.map((location, itemIndex) => itemIndex === index ? { ...location, [field]: value } : location);
  }

  function addProjectDatabaseLocation() {
    projectDatabaseLocations = [...projectDatabaseLocations, { path: '', code: '', default: false }];
  }

  function removeProjectDatabaseLocation(index) {
    projectDatabaseLocations = projectDatabaseLocations.filter((_, itemIndex) => itemIndex !== index);
  }

  function setDefaultProjectDatabaseLocation(index) {
    const selected = projectDatabaseLocations[index].default;
    projectDatabaseLocations = projectDatabaseLocations.map((location, itemIndex) => ({ ...location, default: itemIndex === index ? !selected : false }));
  }

  async function saveProjectLocations() {
    if (projectSettingsSaving || projectLocations.some((location) => !location.path.trim()) || projectDatabaseLocations.some((location) => !location.path.trim())) return;
    projectSettingsSaving = true;
    try {
      const data = await saveProjectsSettings(api, projectLocations.map((location) => ({ ...location, path: location.path.trim() })), projectDatabaseLocations.map((location) => ({ ...location, path: location.path.trim() })));
      projectLocations = data.locations;
      projectDatabaseLocations = data.databaseLocations;
    } catch (cause) {
      errorTitle = 'Не удалось сохранить настройки';
      error = cause instanceof Error ? cause.message : 'Не удалось сохранить расположения проектов.';
      errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
    } finally {
      projectSettingsSaving = false;
    }
  }

  function updateBackupLocation(index, field, value) {
    backupLocations = backupLocations.map((location, itemIndex) => itemIndex === index ? { ...location, [field]: value } : location);
  }

  function addBackupLocation() {
    backupLocations = [...backupLocations, { path: '', code: '', default: false }];
  }

  function removeBackupLocation(index) {
    const wasDefault = backupLocations[index].default;
    backupLocations = backupLocations.filter((_, itemIndex) => itemIndex !== index);
    if (wasDefault && backupLocations.length) backupLocations = backupLocations.map((location, itemIndex) => ({ ...location, default: itemIndex === 0 }));
  }

  function setDefaultBackupLocation(index) {
    backupLocations = backupLocations.map((location, itemIndex) => ({ ...location, default: itemIndex === index }));
  }

  function updateFileStrategy(index, field, value) {
    backupFileStrategies = backupFileStrategies.map((strategy, itemIndex) => itemIndex === index ? { ...strategy, [field]: value } : strategy);
  }

  function addFileStrategy() {
    backupFileStrategies = [...backupFileStrategies, { name: '', code: '', include: [], exclude: [], databaseInclude: [], databaseExclude: [] }];
  }

  function removeFileStrategy(index) {
    backupFileStrategies = backupFileStrategies.filter((_, itemIndex) => itemIndex !== index);
    if (!backupFileStrategies.length) backupFileStrategies = [{ name: '', code: '', include: [], exclude: [], databaseInclude: [], databaseExclude: [] }];
  }

  function openFileStrategySettings(index) {
    const strategy = backupFileStrategies[index];
    fileStrategyDialog = {
      index,
      tab: 'files',
      include: strategy.include.length ? [...strategy.include] : [''],
      exclude: strategy.exclude.length ? [...strategy.exclude] : [''],
      databaseInclude: strategy.databaseInclude.length ? [...strategy.databaseInclude] : [''],
      databaseExclude: strategy.databaseExclude.length ? [...strategy.databaseExclude] : [''],
    };
  }

  function updateStrategyPattern(kind, index, value) {
    fileStrategyDialog = { ...fileStrategyDialog, [kind]: fileStrategyDialog[kind].map((item, itemIndex) => itemIndex === index ? value : item) };
  }

  function addStrategyPattern(kind) {
    fileStrategyDialog = { ...fileStrategyDialog, [kind]: [...fileStrategyDialog[kind], ''] };
  }

  function removeStrategyPattern(kind, index) {
    const patterns = fileStrategyDialog[kind].filter((_, itemIndex) => itemIndex !== index);
    fileStrategyDialog = { ...fileStrategyDialog, [kind]: patterns.length ? patterns : [''] };
  }

  function saveFileStrategySettings() {
    const { index, include, exclude, databaseInclude, databaseExclude } = fileStrategyDialog;
    backupFileStrategies = backupFileStrategies.map((strategy, itemIndex) => itemIndex === index ? {
      ...strategy,
      include: include.map((item) => item.trim()).filter(Boolean),
      exclude: exclude.map((item) => item.trim()).filter(Boolean),
      databaseInclude: databaseInclude.map((item) => item.trim()).filter(Boolean),
      databaseExclude: databaseExclude.map((item) => item.trim()).filter(Boolean),
    } : strategy);
    fileStrategyDialog = null;
  }

  async function saveBackupLocations() {
    if (backupSettingsSaving || backupLocations.some((location) => !location.path.trim()) || backupFileStrategies.some((strategy) => !strategy.name.trim())) return;
    backupSettingsSaving = true;
    try {
      const data = await saveBackupsSettings(api, backupLocations.map((location) => ({ ...location, path: location.path.trim() })), backupFileStrategies.map((strategy) => ({ ...strategy, name: strategy.name.trim(), code: strategy.code.trim() })));
      backupLocations = data.locations;
      backupFileStrategies = data.fileStrategies;
    } catch (cause) {
      errorTitle = 'Не удалось сохранить настройки';
      error = cause instanceof Error ? cause.message : 'Не удалось сохранить расположения бэкапов.';
      errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
    } finally {
      backupSettingsSaving = false;
    }
  }

  async function loadSecuritySettings() {
    if (!authenticated || settingsLoading) return;
    settingsLoading = true;
    try {
      const data = await getSecuritySettings(api);
      maximumSessionHours = Number(data.maximumSessionHours) || 8;
      httpAuthLogin = typeof data.httpAuthLogin === 'string' ? data.httpAuthLogin : '';
      httpAuthPassword = typeof data.httpAuthPassword === 'string' ? data.httpAuthPassword : '';
    } catch (cause) {
      errorTitle = 'Не удалось загрузить настройки';
      error = cause instanceof Error ? cause.message : 'Не удалось загрузить настройки безопасности.';
      errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
    } finally {
      settingsLoading = false;
    }
  }

  async function saveAuthorizationSettings() {
    const hours = Number(maximumSessionHours);
    if (!Number.isInteger(hours) || hours < 1 || hours > 8760) {
      errorTitle = 'Некорректная длительность';
      error = 'Укажите целое число от 1 до 8760 часов.';
      errorStatus = 400;
      return;
    }
    settingsSaving = true;
    try {
      const data = await saveSecuritySettings(api, hours, httpAuthLogin.trim(), httpAuthPassword);
      maximumSessionHours = data.maximumSessionHours;
      httpAuthLogin = typeof data.httpAuthLogin === 'string' ? data.httpAuthLogin : '';
      httpAuthPassword = typeof data.httpAuthPassword === 'string' ? data.httpAuthPassword : '';
      if (data.queuedOperation) notifyQueuedOperation('Применение настроек HTTP-авторизации');
    } catch (cause) {
      errorTitle = 'Не удалось сохранить настройки';
      error = cause instanceof Error ? cause.message : 'Не удалось сохранить настройки безопасности.';
      errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
    } finally {
      settingsSaving = false;
    }
  }

  function applyLogSelection(field, next) {
    if (field === 'project') logProject = normalizeFilterSelection(logProject, next);
    else if (field === 'status') logStatus = normalizeFilterSelection(logStatus, next);
    else if (field === 'level') logLevel = normalizeFilterSelection(logLevel, next);
    else if (field === 'context') logContext = normalizeFilterSelection(logContext, next);
    else {
      const value = Array.isArray(next) ? next[0] : next;
      logType = value === 'hook' ? ['hook'] : ['queue'];
      logStatus = ['all'];
      logLevel = ['all'];
      logContext = ['all'];
      logQueueItem = '';
      logItemCode = '';
      logTaskCode = '';
      logHook = '';
      logCommand = '';
      logTiming = '';
      logHookLevel = '';
      logSort = 'timestamp';
      logDirection = 'desc';
    }
    logPage = 1;
    syncJournalFilters();
    loadLogs();
  }

  function changeLogProject(value) {
    applyLogSelection('project', [value]);
  }

  function changeLogStatus(value) {
    applyLogSelection('status', [value]);
  }

  function logStatusLabel(value) {
    return logStatuses.find((status) => status.value === value)?.label || formatLogValue(value);
  }

  function changeLogCategory(field, value) {
    applyLogSelection(field, [value]);
  }

  function logCategoryLabel(items, value) {
    return items.find((item) => item.value === value)?.label || formatLogValue(value);
  }

  function changeTextLogFilter(field, value) {
    if (field === 'queueItem') logQueueItem = value;
    else if (field === 'itemCode') logItemCode = value;
    else if (field === 'taskCode') logTaskCode = value;
    else if (field === 'hook') logHook = value;
    else if (field === 'command') logCommand = value;
    else if (field === 'timing') logTiming = ['before', 'after'].includes(value) ? value : '';
    logPage = 1;
    syncJournalFilters();
    clearTimeout(logFilterTimer);
    logFilterTimer = setTimeout(loadLogs, 250);
  }

  function openQueueItemJournal(item) {
    logQueueItem = item.file;
    logPage = 1;
    queueOpen = false;
    window.location.hash = journalFilterHash(false);
    if (activeSection === 'logs') loadLogs();
  }

  function changeLogPageSize(value) {
    logPageSize = Number(value);
    logPage = 1;
    loadLogs();
  }

  function sortLogs(field) {
    logDirection = logSort === field && logDirection === 'asc' ? 'desc' : 'asc';
    logSort = field;
    logPage = 1;
    loadLogs();
  }

  function changeLogPage(page) {
    if (page < 1 || page > Math.max(1, Math.ceil(logTotal / logPageSize))) return;
    logPage = page;
    loadLogs();
  }

  function formatLogValue(value) {
    return value === null || value === undefined || value === '' ? '—' : String(value);
  }

  function logRecordProjects(item) {
    if (Array.isArray(item.projects)) return item.projects.filter((project) => typeof project === 'string' && project !== '');
    return typeof item.project === 'string' && item.project !== '' ? [item.project] : [];
  }

  function addProjectTagFromInput() {
    const tag = projectQuery.trim();
    if (!tag || !/^[\p{L}\p{N} -]+$/u.test(tag)) return;
    addProjectTag(tag);
    projectQuery = '';
  }

  function validateProjectQuery(event) {
    const nextValue = event.currentTarget.value;
    projectQuery = [...nextValue].filter((character) => /^[\p{L}\p{N} -]$/u.test(character)).join('');
  }

  function addNoteTag() {
    const tag = noteTagInput.trim();
    if (!tag || !/^[\p{L}\p{N} -]+$/u.test(tag)) return;
    if (!noteTags.includes(tag)) noteTags = [...noteTags, tag];
    noteTagInput = '';
  }

  function removeNoteTag(tag) {
    noteTags = noteTags.filter((item) => item !== tag);
  }

  function validateNoteTagInput(event) {
    const nextValue = event.currentTarget.value;
    noteTagInput = [...nextValue].filter((character) => /^[\p{L}\p{N} -]$/u.test(character)).join('');
  }

  async function saveNotes() {
    if (!selectedProject || notesSaving) return;
    notesSaving = true;
    projectsError = '';
    try {
      const data = await saveProjectNotes(api, selectedProject.name, noteTags, noteDescription);
      projects = data.projects;
    } catch (cause) {
      projectsError = cause instanceof Error ? cause.message : 'Не удалось сохранить заметки.';
    } finally {
      notesSaving = false;
    }
  }

  async function setProjectProtected(protectedProject) {
    if (!selectedProject || securitySaving) return;
    const projectName = selectedProject.name;
    securitySaving = true;
    projectsError = '';
    try {
      const data = await saveProjectSecurity(api, projectName, protectedProject);
      projects = data.projects;
    } catch (cause) {
      projectsError = cause instanceof Error ? cause.message : 'Не удалось сохранить настройки безопасности.';
    } finally {
      securitySaving = false;
    }
  }

  function applyAppearance() {
    document.documentElement.dataset.theme = theme;
    document.documentElement.dataset.font = font;
    document.documentElement.classList.toggle('dark', mode === 'dark' || (mode === 'system' && systemDark));
  }

  function setFont(value) {
    font = value;
    localStorage.setItem(FONT_KEY, font);
    applyAppearance();
  }

  function setTheme(value) {
    theme = value;
    localStorage.setItem(THEME_KEY, theme);
    applyAppearance();
  }

  function setMode(value) {
    mode = value;
    localStorage.setItem(MODE_KEY, mode);
    applyAppearance();
  }

  function closeMenus(event) {
    if (event.target instanceof Element && event.target.closest('.project-context-menu')) return;
    if (event.target instanceof Element && event.target.closest('.user-context-menu')) return;
    if (event.target instanceof Element && event.target.closest('.backup-context-menu')) return;
    if (event.target instanceof Element && event.target.closest('.schedule-context-menu')) return;
    if (event.target instanceof Element && event.target.closest('.hook-context-menu')) return;
    projectContextMenu = null;
    userContextMenu = null;
    backupContextMenu = null;
    scheduleContextMenu = null;
    hookContextMenu = null;
    if (event.target instanceof Element && event.target.closest('.header-menu')) return;
    themeOpen = false;
    notificationsOpen = false;
    profileOpen = false;
    systemOpen = false;
    queueOpen = false;
  }

  function openProjectContextMenu(event, project) {
    if (event.ctrlKey) {
      projectContextMenu = null;
      return;
    }
    event.preventDefault();
    const bounds = event.currentTarget.getBoundingClientRect();
    const x = 'clientX' in event && event.clientX > 0 ? event.clientX : bounds.right;
    const y = 'clientY' in event && event.clientY > 0 ? event.clientY : bounds.top;
    navigateToProject(project.name, projectDetailTab);
    projectContextMenu = {
      project,
      x: Math.max(8, Math.min(x, window.innerWidth - 184)),
      y: Math.max(8, Math.min(y, window.innerHeight - 152)),
    };
  }

  function runContextProjectAction(action) {
    const project = projectContextMenu?.project;
    projectContextMenu = null;
    if (project) requestProjectAction(action, project);
  }

  async function openProjectCloneDialog(project) {
    projectContextMenu = null;
    try {
      projectAddOptions = await getProjectOptions(api);
      const location = projectAddOptions.locations.find((item) => item.default);
      const databaseLocation = projectAddOptions.databaseLocations.find((item) => item.default) ? 'default' : 'system';
      const dedicatedMysql = project.mysqlHost === `docker-cli-mysql-${project.name}`;
      const dedicatedPostgres = project.postgresHost === `docker-cli-postgres-${project.name}`;
      projectCloneDialog = { project: project.name, to: '', location: location?.code || '', mysql: true, postgres: true, dedicated: dedicatedMysql || dedicatedPostgres, dedicatedMysql, dedicatedPostgres, locationMysql: databaseLocation, locationPostgres: databaseLocation };
    } catch (cause) {
      errorTitle = 'Не удалось открыть клонирование проекта';
      error = cause instanceof Error ? cause.message : 'Не удалось загрузить параметры проекта.';
    }
  }

  async function submitProjectClone() {
    if (!projectCloneDialog || projectCloning) return;
    projectCloning = true;
    try {
      const dialog = projectCloneDialog;
      const dbms = [dialog.mysql && 'mysql', dialog.postgres && 'postgres'].filter(Boolean);
      await cloneProject(api, dialog.project, {
        to: dialog.to,
        location: dialog.location,
        dbms,
        dedicatedDatabases: dialog.dedicated ? [dialog.dedicatedMysql && 'mysql', dialog.dedicatedPostgres && 'postgres'].filter(Boolean) : [],
        locationMysql: dialog.locationMysql,
        locationPostgres: dialog.locationPostgres,
      });
      projectCloneDialog = null;
      notifyQueuedOperation(`Клонирование проекта «${dialog.project}»`);
    } catch (cause) {
      errorTitle = 'Не удалось клонировать проект';
      error = cause instanceof Error ? cause.message : 'Не удалось клонировать проект.';
    } finally { projectCloning = false; }
  }

  async function openProjectAddDialog() {
    try {
      projectAddOptions = await getProjectOptions(api);
      const location = projectAddOptions.locations.find((item) => item.default) || projectAddOptions.locations[0];
      const databaseLocation = projectAddOptions.databaseLocations.find((item) => item.default) ? 'default' : 'system';
      const language = projectAddOptions.languages[0];
      projectAddDialog = { code: '', location: location?.code || '', language: language?.code || '', framework: projectAddOptions.frameworks[language?.code]?.[0]?.code || '', deploymentScript: '', deploymentArguments: {}, dedicated: false, mysql: false, postgres: false, locationMysql: databaseLocation, locationPostgres: databaseLocation };
    } catch (cause) {
      errorTitle = 'Не удалось открыть добавление проекта';
      error = cause instanceof Error ? cause.message : 'Не удалось загрузить параметры проекта.';
    }
  }

  async function openProjectUpdateDialog(project) {
    try {
      projectAddOptions = await getProjectOptions(api);
      const language = project.language?.code || projectAddOptions.languages[0]?.code || '';
      projectUpdateDialog = { project: project.name, name: project.name, language, languageVersion: project.languageVersion || projectAddOptions.defaultLanguageVersion, framework: project.framework?.code || '' };
      projectContextMenu = null;
    } catch (cause) {
      errorTitle = 'Не удалось открыть изменение проекта';
      error = cause instanceof Error ? cause.message : 'Не удалось загрузить параметры проекта.';
    }
  }

  function selectDeploymentScript(code) {
    const script = projectAddOptions.deploymentScripts.find((item) => item.code === code);
    const deploymentArguments = {};
    for (const [name, parameter] of Object.entries(script?.parameters || {})) {
      if ('default' in parameter) deploymentArguments[name] = parameter.default;
      else if (parameter.type === 'boolean') deploymentArguments[name] = false;
      else deploymentArguments[name] = '';
    }
    projectAddDialog = { ...projectAddDialog, deploymentScript: code, deploymentArguments };
  }

  function setDeploymentArgument(name, value) {
    projectAddDialog = { ...projectAddDialog, deploymentArguments: { ...projectAddDialog.deploymentArguments, [name]: value } };
  }

  async function addProject() {
    if (!projectAddDialog) return;
    projectAdding = true;
    try {
      const data = await createProject(api, {
        ...projectAddDialog,
        dedicatedDatabases: projectAddDialog.dedicated ? ['mysql', 'postgres'].filter((driver) => projectAddDialog[driver]) : [],
      });
      projects = data.projects;
      projectAddDialog = null;
      notifyQueuedOperation('Добавление проекта');
    } catch (cause) {
      errorTitle = 'Не удалось добавить проект';
      error = cause instanceof Error ? cause.message : 'Не удалось добавить проект.';
    }
    finally { projectAdding = false; }
  }

  async function submitProjectUpdate() {
    if (!projectUpdateDialog || projectUpdating) return;
    projectUpdating = true;
    try {
      const data = await updateProject(api, projectUpdateDialog.project, { name: projectUpdateDialog.name, language: projectUpdateDialog.language, languageVersion: projectUpdateDialog.languageVersion, framework: projectUpdateDialog.framework });
      projects = data.projects;
      projectUpdateDialog = null;
      notifyQueuedOperation('Изменение проекта');
    } catch (cause) {
      errorTitle = 'Не удалось изменить проект';
      error = cause instanceof Error ? cause.message : 'Не удалось изменить проект.';
    } finally { projectUpdating = false; }
  }

  async function api(path, options = {}) {
    let response;
    try {
      response = await fetch(path, {
        ...options,
        headers: {
          'Content-Type': 'application/json',
          ...(options.headers || {}),
        },
      });
    } catch {
      throw new Error('Панель временно недоступна — возможно, она перезапускается. Подождите несколько секунд и повторите запрос.');
    }
    let data;
    try {
      data = await response.json();
    } catch {
      const cause = Object.assign(new Error('Сервер вернул некорректный ответ.'), { status: response.status });
      throw cause;
    }
    if (!response.ok) {
      const cause = Object.assign(new Error(data.error || 'Не удалось выполнить запрос.'), { status: response.status });
      throw cause;
    }
    return data;
  }

  function acceptSession(data, resetNavigation = false) {
    authenticated = true;
    currentLogin = data.login;
    if (resetNavigation || window.location.hash === '#/login') navigateToProject('', 'info');
    else applyHashNavigation();
    connectPanelChannel();
  }

  function logout() {
    fetch('/api/auth/logout', { method: 'POST' }).catch(() => {});
    authenticated = false;
    currentLogin = '';
    profileOpen = false;
    projectsLoading = false;
    projectsError = '';
    notifications = [];
    notificationsInitialized = false;
    knownNotificationFiles.clear();
    logRequestId += 1;
    disconnectPanelChannel(false);
    window.location.hash = '#/login';
  }

  async function checkSession() {
    if (!authenticated || systemPending) return;
    try {
      acceptSession(await api('/api/auth/session'));
    } catch (cause) {
      // A temporary proxy or network failure does not invalidate the JWT.
      // Only the backend can explicitly tell us that the session has expired.
      if (cause instanceof Error && 'status' in cause && cause.status === 401) logout();
    }
  }

  function applyPanelState(data) {
    if (!data || !Array.isArray(data.projects) || !data.system || !Array.isArray(data.system.services)) return;
    projects = data.projects;
    projectsError = '';
    systemStatus = data.system.status;
    systemServices = data.system.services;
    if (data.queue?.name === 'default' && Array.isArray(data.queue.items)) {
      queueItems = data.queue.items;
      queuePaused = data.queue.paused === true;
    }
    if (data.notifications && Array.isArray(data.notifications.notifications)) {
      const receivedNotifications = data.notifications.notifications;
      notifyNewNotifications(receivedNotifications);
      notifications = receivedNotifications;
    }
    projectsLoading = false;
    if (selectedProjectName && !projects.some((project) => project.name === selectedProjectName)) navigateToProject('', 'info');
  }

  function connectPanelChannel() {
    if (!panelChannelEnabled || !authenticated || panelSocket?.readyState === WebSocket.OPEN || panelSocket?.readyState === WebSocket.CONNECTING) return;
    clearTimeout(panelReconnectTimer);
    if (projects.length === 0) projectsLoading = true;
    const protocol = location.protocol === 'https:' ? 'wss:' : 'ws:';
    const query = new URLSearchParams({ channel: PANEL_CHANNEL });
    const socket = new WebSocket(`${protocol}//${location.host}/ws?${query}`);
    panelSocket = socket;
    socket.onmessage = (event) => {
      try {
        const message = JSON.parse(event.data);
        if (message.channel === PANEL_CHANNEL) applyPanelState(message.data);
      } catch {
        // Ignore malformed messages and keep waiting for the next state snapshot.
      }
    };
    socket.onclose = () => {
      if (panelSocket === socket) panelSocket = null;
      if (panelChannelEnabled && authenticated) panelReconnectTimer = setTimeout(connectPanelChannel, 1_000);
    };
  }

  function disconnectPanelChannel(disable = true) {
    if (disable) panelChannelEnabled = false;
    clearTimeout(panelReconnectTimer);
    panelSocket?.close();
    panelSocket = null;
  }

  function formatQueueDate(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString('ru-RU');
  }

  async function archiveNotification(notification) {
    try {
      const data = await api(`/api/notifications/${encodeURIComponent(notification.file)}`, { method: 'DELETE' });
      notifications = data.notifications;
    } catch (cause) {
      errorTitle = 'Не удалось удалить уведомление';
      error = cause instanceof Error ? cause.message : 'Не удалось переместить уведомление в архив.';
      errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
    }
  }

  async function archiveAllNotifications() {
    try {
      const data = await api('/api/notifications', { method: 'DELETE' });
      notifications = data.notifications;
    } catch (cause) {
      errorTitle = 'Не удалось очистить уведомления';
      error = cause instanceof Error ? cause.message : 'Не удалось переместить уведомления в архив.';
      errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
    }
  }

  async function notifyNewNotifications(receivedNotifications) {
    if (!notificationsInitialized) {
      receivedNotifications.forEach((notification) => knownNotificationFiles.add(notification.file));
      notificationsInitialized = true;
      return;
    }
    const newNotifications = receivedNotifications.filter((notification) => !knownNotificationFiles.has(notification.file));
    newNotifications.forEach((notification) => knownNotificationFiles.add(notification.file));
    if (newNotifications.length === 0) return;
    void pageRefresh.refresh();
    if (!('Notification' in window)) return;
    let permission = Notification.permission;
    if (permission === 'default') {
      try {
        permission = await Notification.requestPermission();
      } catch {
        return;
      }
    }
    if (permission !== 'granted') return;
    newNotifications.forEach((notification) => {
      new Notification('docker-cli', { body: notification.message, tag: notification.file });
    });
  }

  function renderNotificationMarkdown(message) {
    return micromark(message, { allowDangerousHtml: false, allowDangerousProtocol: false });
  }

  async function deleteQueueItem(item) {
    queueConfirmation = null;
    try {
      const data = await api(`/api/queue/default/${encodeURIComponent(item.file)}`, { method: 'DELETE' });
      queueItems = data.items;
    } catch (cause) {
      errorTitle = 'Не удалось удалить элемент очереди';
      error = cause instanceof Error ? cause.message : 'Не удалось удалить элемент очереди.';
      errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
    }
  }

  async function archiveQueueItem(item) {
    try {
      const data = await api(`/api/queue/default/${encodeURIComponent(item.file)}/archive`, { method: 'POST' });
      queueItems = data.items;
    } catch (cause) {
      errorTitle = 'Не удалось архивировать элемент очереди';
      error = cause instanceof Error ? cause.message : 'Не удалось архивировать элемент очереди.';
      errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
    }
  }

  async function toggleQueue() {
    if (queueActionPending) return;
    queueActionPending = true;
    try {
      const action = queuePaused ? 'resume' : 'pause';
      const data = await api(`/api/queue/default/${action}`, { method: 'POST' });
      queuePaused = data.paused === true;
      queueItems = data.items;
    } catch (cause) {
      errorTitle = queuePaused ? 'Не удалось возобновить очередь' : 'Не удалось приостановить очередь';
      error = cause instanceof Error ? cause.message : 'Не удалось изменить состояние очереди.';
      errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
    } finally {
      queueActionPending = false;
    }
  }

  function notifyQueuedOperation(description) {
    queuedOperationNotice = `${description} поставлена в очередь.`;
  }

  async function projectAction(action, name) {
    systemPending = true;
    projectsError = '';
    systemPendingMessage = action === 'wipe' || action === 'delete'
      ? `Добавляем ${action === 'delete' ? 'удаление' : 'очистку'} проекта «${name}» в очередь…`
      : `${action === 'enable' ? 'Включаем' : 'Отключаем'} проект «${name}»…`;
    try {
      const data = await runProjectAction(api, name, action);
      projects = data.projects;
      if (action === 'wipe') notifyQueuedOperation(`Очистка проекта «${name}»`);
      if (action === 'delete') notifyQueuedOperation(`Удаление проекта «${name}»`);
      if (action !== 'wipe' && action !== 'delete' && !projectHasState(data.projects, action, name) && !(await waitForProjectAction(action, name))) {
        throw Object.assign(new Error('Не удалось дождаться подтверждения статуса проекта.'), { status: 504 });
      }
    } catch (cause) {
      let reconciled = false;
      if (action !== 'wipe' && action !== 'delete' && !(cause instanceof Error && 'status' in cause)) {
        reconciled = await waitForProjectAction(action, name);
      }
      if (!reconciled) {
        errorTitle = 'Не удалось выполнить действие';
        error = cause instanceof Error ? cause.message : 'Не удалось выполнить действие.';
        errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
      }
    } finally {
      systemPending = false;
    }
  }

  async function waitForProjectAction(action, name) {
    for (let attempt = 0; attempt < 60; attempt += 1) {
      try {
        const data = await getProjects(api);
        projects = data.projects;
        if (projectHasState(data.projects, action, name)) return true;
      } catch {
        // Keep the blocking dialog open during a short-lived API outage.
      }
      await new Promise((resolve) => setTimeout(resolve, 1_000));
    }
    return false;
  }

  function projectHasState(items, action, name) {
    const project = items.find((item) => item.name === name);
    return action === 'enable' ? project?.enabled === true : project?.enabled === false;
  }

  function requestProjectAction(action, project) {
    if ((action === 'wipe' || action === 'delete') && project.protected) {
      protectedAlert = project;
      return;
    }
    if (action === 'disable' || action === 'wipe' || action === 'delete') {
      projectConfirmation = { action, project };
      return;
    }
    projectAction(action, project.name);
  }

  function systemServiceUrl(service) {
    if (!['mailpit', 'dockhand', 'adminer'].includes(service.name)) return null;
    const baseHost = window.location.hostname.replace(/^panel\./, '');
    return `https://${service.name}.${baseHost}`;
  }

  async function systemAction(action, service = '') {
    systemOpen = false;
    systemPending = true;
    projectsError = '';
    systemPendingMessage = action === 'restart'
      ? 'Система перезапускается. Ждём восстановления необходимых компонентов…'
      : 'Ждём выполнения запроса. Пожалуйста, не закрывайте страницу.';
    const affectsPanel = !service || panelServices.includes(service);
    try {
      const data = await runSystemAction(api, action, service);
      systemStatus = data.status;
      systemServices = data.services;
      if (action === 'restart' && affectsPanel && !(await waitForPanelServices())) {
        throw Object.assign(new Error('Не удалось дождаться восстановления компонентов панели.'), { status: 504 });
      }
    } catch (cause) {
      // The proxy connection can be interrupted while Docker has already
      // completed the operation. Reconcile transport errors with fresh state
      // before telling the user that the action failed.
      let reconciled = false;
      if (action === 'restart' && affectsPanel && !(cause instanceof Error && 'status' in cause)) {
        reconciled = await waitForPanelServices();
      } else if (action === 'stop' && affectsPanel && !(cause instanceof Error && 'status' in cause)) {
        // Losing the control-plane connection is the expected result here.
        reconciled = true;
      } else if (!(cause instanceof Error && 'status' in cause)) {
        try {
          const data = await getSystemStatus(api);
          systemStatus = data.status;
          systemServices = data.services;
          const target = service ? data.services.find((item) => item.name === service) : null;
          reconciled = action === 'restart'
            || (action === 'start' && (service ? target?.running === true : data.services.every((item) => item.running)))
            || (action === 'stop' && (service ? target?.running === false : data.services.every((item) => !item.running)));
        } catch {
          // Keep the original transport error when status cannot be refreshed.
        }
      }
      if (!reconciled) {
        errorTitle = 'Не удалось выполнить действие';
        error = cause instanceof Error ? cause.message : 'Не удалось выполнить действие.';
        errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
      }
    } finally {
      systemPending = false;
    }
  }

  function requestSystemAction(action, service = '') {
    const affectsPanel = !service || panelServices.includes(service);
    if (affectsPanel && (action === 'stop' || action === 'restart')) {
      systemOpen = false;
      systemConfirmation = { action, service };
      return;
    }
    systemAction(action, service);
  }

  async function enqueueSystemUpdate() {
    if (systemUpdatePending) return;
    systemOpen = false;
    systemUpdatePending = true;
    try {
      await queueSystemSelfUpdate(api);
      notifyQueuedOperation('Обновление docker-cli');
    } catch (cause) {
      errorTitle = 'Не удалось запустить обновление';
      error = cause instanceof Error ? cause.message : 'Не удалось добавить обновление docker-cli в очередь.';
      errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
    } finally {
      systemUpdatePending = false;
    }
  }

  async function waitForPanelServices() {
    for (let attempt = 0; attempt < 120; attempt += 1) {
      try {
        const data = await getSystemStatus(api);
        systemStatus = data.status;
        systemServices = data.services;
        if (panelServices.every((name) => data.services.find((service) => service.name === name)?.running)) return true;
      } catch {
        // The gateway is expected to be unreachable for part of a restart.
      }
      await new Promise((resolve) => setTimeout(resolve, 1_000));
    }
    return false;
  }

  async function submit() {
    error = '';
    errorStatus = 0;
    errorTitle = 'Не удалось войти';
    submitting = true;
    try {
      const data = await api('/api/auth/login', {
        method: 'POST',
        body: JSON.stringify({ login, password }),
      });
      password = '';
      acceptSession(data, true);
    } catch (cause) {
      error = cause instanceof Error ? cause.message : 'Не удалось выполнить вход.';
      errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
    } finally {
      submitting = false;
    }
  }

  onMount(() => {
    const media = matchMedia('(prefers-color-scheme: dark)');
    systemDark = media.matches;
    const savedTheme = localStorage.getItem(THEME_KEY);
    theme = themes.some(([value]) => value === savedTheme) ? savedTheme : 'vox';
    const savedEditorTheme = localStorage.getItem(EDITOR_THEME_KEY);
    editorTheme = editorThemes.some(([value]) => value === savedEditorTheme) ? savedEditorTheme : 'github-light';
    const savedMode = localStorage.getItem(MODE_KEY);
    mode = modes.some(([value]) => value === savedMode) ? savedMode : 'system';
    const savedFont = localStorage.getItem(FONT_KEY);
    font = fonts.some((item) => item.value === savedFont) ? savedFont : 'ubuntu';
    applyAppearance();
    const updateSystemMode = (event) => {
      systemDark = event.matches;
      if (mode === 'system') applyAppearance();
    };
    media.addEventListener('change', updateSystemMode);
    authenticated = true;
    if (!window.location.hash) window.location.hash = '#/login';
    else applyHashNavigation();
    window.addEventListener('hashchange', applyHashNavigation);
    panelChannelEnabled = true;
    connectPanelChannel();
    checkSession().finally(() => { loading = false; });
    const interval = setInterval(checkSession, 60_000);
    return () => {
      clearInterval(interval);
      clearTimeout(logFilterTimer);
      disconnectPanelChannel();
      window.removeEventListener('hashchange', applyHashNavigation);
      media.removeEventListener('change', updateSystemMode);
    };
  });
</script>

<svelte:window
  onclick={closeMenus}
  onkeydown={(event) => { if (event.key === 'Escape') { themeOpen = false; editorThemeOpen = false; notificationsOpen = false; profileOpen = false; systemOpen = false; queueOpen = false; backupContextMenu = null; } }}
/>

<svelte:head><title>{authenticated ? 'docker-cli' : 'Вход — docker-cli'}</title></svelte:head>

<div class:panel-shell={authenticated && !loading} class="min-h-screen bg-surface-50-950 text-surface-950-50 flex flex-col">
  <header class="app-header h-16 border-b border-surface-200-800 bg-surface-100-900 flex items-center px-5 md:px-8 shadow-sm">
    {#if authenticated}<a href="#/projects" class="font-bold text-xl no-underline">docker-cli</a>{/if}
    {#if authenticated}
      <div class="system-header header-menu">
        <div class="queue-main-control">
          <button class="btn preset-tonal system-trigger" type="button" aria-expanded={queueOpen} onclick={() => { queueOpen = !queueOpen; systemOpen = false; themeOpen = false; profileOpen = false; }}>
            <span class={`queue-summary-dot ${queueStatus}`} aria-hidden="true"></span><span>Очередь</span>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5" /></svg>
          </button>
          {#if queueOpen}
            <div class="queue-menu card preset-filled-surface-100-900 shadow-2xl">
              <div class="queue-menu-actions">
                <button class="btn btn-sm preset-tonal" type="button" disabled={queueActionPending} onclick={toggleQueue}>
                  {#if queuePaused}<Play size={14} aria-hidden="true" />{:else}<Square size={14} aria-hidden="true" />{/if}
                  {queueActionPending ? 'Подождите…' : queuePaused ? 'Возобновить' : 'Приостановить'}
                </button>
              </div>
              <div class="system-menu-divider" aria-hidden="true"></div>
              {#if queueItems.length === 0}<p class="system-empty">Очередь пуста</p>{/if}
              {#each queueItems as item (item.file)}
                <div class="queue-item">
                  <span class={`queue-dot status-${item.status}`} aria-hidden="true"></span>
                  <time datetime={item.queuedAt}>{formatQueueDate(item.queuedAt)}</time>
                  <button class="queue-item-code queue-item-link" type="button" title={`Открыть журнал элемента ${item.code}`} onclick={() => openQueueItemJournal(item)}>{item.code}</button>
                  {#if item.status !== '20-active'}
                    <div class="queue-item-actions">
                      <button class="btn btn-sm preset-tonal" type="button" onclick={() => archiveQueueItem(item)}><Archive size={14} aria-hidden="true" />Архивировать</button>
                      <button class="btn-icon preset-tonal" type="button" aria-label="Удалить элемент очереди" title="Удалить" onclick={() => { queueOpen = false; queueConfirmation = item; }}><Trash2 size={14} aria-hidden="true" /></button>
                    </div>
                  {/if}
                </div>
              {/each}
            </div>
          {/if}
        </div>
        <div class="system-main-control">
          <button class="btn preset-tonal system-trigger" type="button" aria-expanded={systemOpen} onclick={() => { systemOpen = !systemOpen; queueOpen = false; themeOpen = false; profileOpen = false; }}>
            <span class={`system-dot ${systemStatus}`} aria-hidden="true"></span><span>Система</span>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5" /></svg>
          </button>
          {#if systemOpen}
            <div class="system-menu card preset-filled-surface-100-900 shadow-2xl">
              <div class="system-menu-global-actions">
                {#if hasStoppedServices}<button class="btn btn-sm preset-tonal" type="button" onclick={() => requestSystemAction('start')}><Play size={14} aria-hidden="true" />Запустить</button>{/if}
                {#if hasRunningServices}<button class="btn btn-sm preset-tonal" type="button" onclick={() => requestSystemAction('stop')}><Square size={14} aria-hidden="true" />Остановить</button>{/if}
                <button class="btn btn-sm preset-tonal" type="button" onclick={() => requestSystemAction('restart')}><RotateCw size={14} aria-hidden="true" />Перезапустить</button>
              </div>
              <div class="system-menu-divider" aria-hidden="true"></div>
              <div class="system-menu-global-actions">
                <button class="btn btn-sm preset-tonal" type="button" disabled={systemUpdatePending} onclick={enqueueSystemUpdate}><Download size={14} aria-hidden="true" />{systemUpdatePending ? 'Добавляем…' : 'Обновить'}</button>
              </div>
              <div class="system-menu-divider" aria-hidden="true"></div>
              {#if systemServices.length === 0}<p class="system-empty">Сервисы не найдены</p>{/if}
              {#each systemServices as service (service.name)}
                {@const serviceUrl = systemServiceUrl(service)}
                <div class="system-service">
                  <span class={`system-dot ${service.running ? 'running' : 'stopped'}`} aria-hidden="true"></span>
                  {#if serviceUrl}
                    <a class="system-service-name system-service-link" href={serviceUrl} target="_blank" rel="noopener noreferrer" title={service.image}>{service.name}<ExternalLink size={13} aria-hidden="true" /></a>
                  {:else}
                    <span class="system-service-name" title={service.image}>{service.name}</span>
                  {/if}
                  <div class="system-actions">
                    <button class="btn btn-sm preset-tonal" type="button" onclick={() => requestSystemAction(service.running ? 'stop' : 'start', service.name)}>
                      {#if service.running}<Square size={14} aria-hidden="true" />{:else}<Play size={14} aria-hidden="true" />{/if}
                      {service.running ? 'Остановить' : 'Запустить'}
                    </button>
                    <button class="btn btn-sm preset-tonal" type="button" onclick={() => requestSystemAction('restart', service.name)}><RotateCw size={14} aria-hidden="true" />Перезапустить</button>
                  </div>
                </div>
              {/each}
            </div>
          {/if}
        </div>
      </div>
    {/if}
    <div class="ml-auto flex items-center gap-3">
      {#if authenticated}
        <div class="relative header-menu">
          <button class="btn-icon preset-tonal notification-trigger" type="button" aria-label="Уведомления" aria-haspopup="dialog" aria-expanded={notificationsOpen} onclick={() => { notificationsOpen = !notificationsOpen; themeOpen = false; profileOpen = false; systemOpen = false; queueOpen = false; }}>
            <Bell size={19} aria-hidden="true" />
            {#if notifications.length > 0}<span class={`notification-badge ${notificationBadgeLevel}`}>{notifications.length}</span>{/if}
          </button>
          {#if notificationsOpen}
            <div class="notification-menu card preset-filled-surface-100-900 absolute right-0 mt-2 shadow-2xl z-20" role="dialog" aria-label="Уведомления">
              <div class="notification-menu-actions">
                <button class="btn btn-sm preset-tonal" type="button" disabled={notifications.length === 0} onclick={archiveAllNotifications}>Очистить</button>
              </div>
              <div class="system-menu-divider" aria-hidden="true"></div>
              {#if notifications.length === 0}<p class="notification-empty">Уведомлений нет</p>{/if}
              {#each notifications as notification (notification.file)}
                <article class="notification-item">
                  <div>
                    <time datetime={notification.time}>{formatQueueDate(notification.time)}</time>
                    <div class="notification-message">{@html renderNotificationMarkdown(notification.message)}</div>
                  </div>
                  <button class="btn-icon preset-tonal notification-delete" type="button" aria-label="Удалить уведомление" title="Удалить" onclick={() => archiveNotification(notification)}><Trash2 size={16} aria-hidden="true" /></button>
                </article>
              {/each}
            </div>
          {/if}
        </div>
      {/if}
      <div class="relative header-menu">
        <button class="btn-icon preset-tonal theme-trigger" type="button" aria-label="Настроить оформление" aria-haspopup="dialog" aria-expanded={themeOpen} onclick={() => { themeOpen = !themeOpen; notificationsOpen = false; profileOpen = false; }}>
          <span class="theme-dot" aria-hidden="true"></span>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5" /></svg>
        </button>
        {#if themeOpen}
          <div class="theme-menu card preset-filled-surface-100-900 absolute right-0 mt-2 p-4 shadow-2xl z-20" role="dialog" aria-label="Настройки оформления">
            <div class="flex items-center justify-between mb-3">
              <strong>Тема</strong>
              <span class="text-sm text-surface-500">{themes.find(([value]) => value === theme)?.[1]}</span>
            </div>
            <div class="theme-grid" role="list" aria-label="Цветовая тема">
              {#each themes as [value, label]}
                <button class:active={theme === value} class="theme-option" type="button" data-theme={value} aria-label={label} aria-pressed={theme === value} title={label} onclick={() => setTheme(value)}>
                  <span class="swatch"><i></i><i></i><i></i></span>
                  <span>{label}</span>
                </button>
              {/each}
            </div>
            <div class="mode-switch mt-4" aria-label="Цветовой режим">
              {#each modes as [value, label]}
                <button class:active={mode === value} type="button" aria-pressed={mode === value} onclick={() => setMode(value)}>
                  <span aria-hidden="true">{value === 'light' ? '☀' : value === 'dark' ? '☾' : '◐'}</span>
                  {label}
                </button>
              {/each}
            </div>
            <div class="font-switch mt-4">
              <span id="font-switch-label">Шрифт</span>
              <Combobox
                collection={fontCollection}
                value={[font]}
                openOnClick
                onValueChange={(details) => details.value[0] && setFont(details.value[0])}
              >
                <Combobox.Control class="font-combobox-control">
                  <Combobox.Input aria-labelledby="font-switch-label" class="font-combobox-input" readonly />
                  <Combobox.Trigger class="font-combobox-trigger" />
                </Combobox.Control>
                <Combobox.Positioner class="font-combobox-positioner">
                  <Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">
                    {#each fonts as item}
                      <Combobox.Item {item} class="font-combobox-item">
                        <Combobox.ItemText>{item.label}</Combobox.ItemText>
                        <Combobox.ItemIndicator class="font-combobox-indicator" />
                      </Combobox.Item>
                    {/each}
                  </Combobox.Content>
                </Combobox.Positioner>
              </Combobox>
            </div>
          </div>
        {/if}
      </div>
      {#if authenticated}
        <div class="relative header-menu">
          <button class="btn preset-tonal" type="button" aria-expanded={profileOpen} onclick={() => { profileOpen = !profileOpen; themeOpen = false; editorThemeOpen = false; notificationsOpen = false; }}>{currentLogin}</button>
          {#if profileOpen}
            <div class="card preset-filled-surface-100-900 absolute right-0 mt-2 min-w-44 p-2 shadow-xl z-10">
              <button class="btn w-full justify-start hover:preset-tonal-error" type="button" onclick={logout}>Выйти</button>
            </div>
          {/if}
        </div>
      {/if}
    </div>
  </header>

  <main class:workspace={authenticated && !loading} class="flex-1 flex items-center justify-center p-5">
    {#if loading}
      <div class="animate-pulse text-surface-500">Проверка сессии…</div>
    {:else if !authenticated}
      <section class="card preset-filled-surface-100-900 w-full max-w-md p-7 md:p-9 shadow-xl" aria-labelledby="login-title">
        <h1 id="login-title" class="h2 text-center mb-2">Вход в панель</h1>
        <p class="text-center text-surface-500 mb-8">Введите данные пользователя docker-cli</p>
        <form class="space-y-5" onsubmit={(event) => { event.preventDefault(); submit(); }}>
          <label class="label">
            <span class="label-text">Логин</span>
            <input class="input" type="email" bind:value={login} autocomplete="username" placeholder="admin@example.com" required />
          </label>
          <label class="label">
            <span class="label-text">Пароль</span>
            <input class="input" type="password" bind:value={password} autocomplete="current-password" required />
          </label>
          <button class="btn preset-filled-primary-500 w-full" type="submit" disabled={submitting}>
            {submitting ? 'Входим…' : 'Войти'}
          </button>
        </form>
      </section>
    {:else}
      <section class="projects-view" aria-label="Рабочая область">
        <nav class="tabs" aria-label="Разделы панели">
          <a class:active={activeSection === 'projects'} class="tab" href="#/projects" aria-current={activeSection === 'projects' ? 'page' : undefined}>Проекты</a>
          <a class:active={activeSection === 'logs'} class="tab" href="#/journal" aria-current={activeSection === 'logs' ? 'page' : undefined}>Журнал</a>
          <a class:active={activeSection === 'settings'} class="tab" href="#/settings/projects" aria-current={activeSection === 'settings' ? 'page' : undefined}>Настройки</a>
        </nav>
        {#if activeSection === 'projects'}
        <div class="projects-layout">
          <aside class="project-sidebar" aria-label="Список проектов">
            <div class="project-sidebar-title">
              <h1>Проекты</h1>
              <span>{projects.length}</span>
            </div>
            <button class="btn preset-filled-primary-500 project-add-button" type="button" onclick={openProjectAddDialog}><Plus size={16} aria-hidden="true" />Добавить</button>
            <label class="project-search" aria-label="Поиск проектов">
              <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m16 16 4 4"></path></svg>
              {#each projectTags as tag (tag)}
                <span class="search-tag">
                  {tag}
                  <button type="button" aria-label={`Удалить тег ${tag}`} onclick={() => removeProjectTag(tag)}>×</button>
                </span>
              {/each}
              <input
                type="search"
                value={projectQuery}
                oninput={validateProjectQuery}
                onkeydown={(event) => { if (event.key === 'Enter') { event.preventDefault(); addProjectTagFromInput(); } }}
                placeholder={projectTags.length ? 'Название или новый тег…' : 'Название или тег…'}
              />
            </label>
            <div class="project-list-scroll">
              {#if projectsLoading}
                <p class="project-message animate-pulse">Загрузка проектов…</p>
              {:else if filteredProjects.length === 0}
                <p class="project-message">{projects.length ? 'Ничего не найдено' : 'Проекты не найдены'}</p>
              {:else}
                <div class="project-list">
                  {#each filteredProjects as project (project.name)}
                    <div
                      role="button"
                      tabindex="0"
                      class="project-item"
                      class:selected={selectedProjectName === project.name}
                      aria-pressed={selectedProjectName === project.name}
                      onclick={() => navigateToProject(project.name, 'info')}
                      oncontextmenu={(event) => openProjectContextMenu(event, project)}
                      onkeydown={(event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); navigateToProject(project.name, 'info'); } else if (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10')) { openProjectContextMenu(event, project); } }}
                    >
                      <span class:enabled={project.enabled} class="status-dot" title={project.enabled ? 'Включен' : 'Выключен'}></span>
                      <span class="project-summary">
                        <span class="project-name">
                          <strong>{project.name}</strong>
                          {#if project.description.trim()}
                            <Tooltip positioning={{ placement: 'top' }}>
                              <Tooltip.Trigger class="project-notes-help" aria-label={`Заметки проекта ${project.name}`} onclick={(event) => event.stopPropagation()}><CircleHelp size={14} aria-hidden="true" /></Tooltip.Trigger>
                              <Tooltip.Positioner><Tooltip.Content class="project-notes-tooltip card preset-filled-surface-900-100 shadow-xl">{project.description}</Tooltip.Content></Tooltip.Positioner>
                            </Tooltip>
                          {/if}
                          {#if project.protected}<Lock size={14} aria-label="Защищённый проект" />{/if}
                        </span>
                        <span class="project-tags">
                          {#each [{ code: project.language?.code || 'no-language', name: project.language?.name || 'no-language' }, { code: project.framework?.code || 'no-framework', name: project.framework?.name || 'Без фреймворка' }, ...project.tags.map((tag) => ({ code: tag, name: tag }))] as tag}
                            <button type="button" onclick={(event) => { event.stopPropagation(); addProjectTag(tag.code); }}>{tag.name}</button>
                          {/each}
                        </span>
                      </span>
                    </div>
                  {/each}
                </div>
              {/if}
            </div>
          </aside>
          <div class="project-details">
            {#if selectedProject}
              <nav class="project-detail-tabs" aria-label={`Разделы проекта ${selectedProject.name}`}>
                <a class:active={projectDetailTab === 'info'} class="project-detail-tab" href={projectHash(selectedProject.name, 'info')} aria-current={projectDetailTab === 'info' ? 'page' : undefined}>Общее</a>
                <a class:active={projectDetailTab === 'notes'} class="project-detail-tab" href={projectHash(selectedProject.name, 'notes')} aria-current={projectDetailTab === 'notes' ? 'page' : undefined}>Заметки</a>
                <a class:active={projectDetailTab === 'security'} class="project-detail-tab" href={projectHash(selectedProject.name, 'security')} aria-current={projectDetailTab === 'security' ? 'page' : undefined}>Безопасность</a>
                <a class:active={projectDetailTab === 'backups'} class="project-detail-tab" href={projectHash(selectedProject.name, 'backups')} aria-current={projectDetailTab === 'backups' ? 'page' : undefined}>Бэкапы</a>
                <a class:active={projectDetailTab === 'scheduler'} class="project-detail-tab" href={projectHash(selectedProject.name, 'scheduler')} aria-current={projectDetailTab === 'scheduler' ? 'page' : undefined}>Планировщик</a>
                <a class:active={projectDetailTab === 'journal'} class="project-detail-tab" href={projectHash(selectedProject.name, 'journal')} aria-current={projectDetailTab === 'journal' ? 'page' : undefined}>Журнал</a>
              </nav>
              <div class="project-details-scroll" class:table-tab={projectDetailTab === 'journal' || projectDetailTab === 'backups' || projectDetailTab === 'scheduler'}>
                {#if projectDetailTab === 'info'}
                <section class="project-tab-content card preset-filled-surface-100-900" aria-label="Общее">
                  <dl class="project-fields">
                    <div><dt>Название</dt><dd>{selectedProject.name}</dd></div>
                    <div><dt>Язык</dt><dd>{selectedProject.language?.name ? `${selectedProject.language.name}${selectedProject.languageVersion ? ` ${selectedProject.languageVersion}` : ''}` : 'Не указан'}</dd></div>
                    <div><dt>Фреймворк</dt><dd>{selectedProject.framework?.name || 'Без фреймворка'}</dd></div>
                    <div><dt>Статус</dt><dd class:enabled={selectedProject.enabled} class="status-value"><i></i>{selectedProject.enabled ? 'Включен' : 'Выключен'}</dd></div>
                    <div><dt>Основной хост</dt><dd>{#if selectedProject.url}<a class="project-host" href={selectedProject.url} target="_blank" rel="noreferrer">{selectedProject.url}<ExternalLink size={14} aria-hidden="true" /></a>{:else}Не указан{/if}</dd></div>
                    <div><dt>Хост MySQL</dt><dd><code>{selectedProject.mysqlHost || 'docker-cli-mysql'}</code></dd></div>
                    <div><dt>Хост PostgreSQL</dt><dd><code>{selectedProject.postgresHost || 'docker-cli-postgres'}</code></dd></div>
                  </dl>
                  <div class="project-general-actions">
                    <button class="btn preset-tonal" type="button" onclick={() => openProjectUpdateDialog(selectedProject)}>
                      <Pencil size={16} aria-hidden="true" />Изменить
                    </button>
                    <button class="btn preset-tonal" type="button" onclick={() => openProjectCloneDialog(selectedProject)}>
                      <Copy size={16} aria-hidden="true" />Клонировать
                    </button>
                    <button class="btn preset-tonal" type="button" onclick={() => requestProjectAction(selectedProject.enabled ? 'disable' : 'enable', selectedProject)}>
                      <Power size={16} aria-hidden="true" />{selectedProject.enabled ? 'Отключить' : 'Включить'}
                    </button>
                    <button class="btn preset-filled-error-500" type="button" onclick={() => requestProjectAction('wipe', selectedProject)}>
                      <Trash2 size={16} aria-hidden="true" />Стереть
                    </button>
                    <button class="btn preset-filled-error-500" type="button" onclick={() => requestProjectAction('delete', selectedProject)}>
                      <Trash2 size={16} aria-hidden="true" />Удалить
                    </button>
                  </div>
                </section>
                {:else if projectDetailTab === 'notes'}
                <div class="project-toolbar">
                  <button class="btn preset-filled-primary-500" type="button" disabled={notesSaving} onclick={saveNotes}>
                    <Save size={16} aria-hidden="true" />{notesSaving ? 'Сохраняем…' : 'Сохранить'}
                  </button>
                </div>
                <section class="project-tab-content notes-content card preset-filled-surface-100-900" aria-label="Заметки">
                  <label class="label">
                    <span class="label-text">Теги</span>
                    <span class="notes-tags-input input">
                      {#each noteTags as tag (tag)}
                        <span class="search-tag">{tag}<button type="button" aria-label={`Удалить тег ${tag}`} onclick={() => removeNoteTag(tag)}>×</button></span>
                      {/each}
                      <input value={noteTagInput} oninput={validateNoteTagInput} onkeydown={(event) => { if (event.key === 'Enter') { event.preventDefault(); addNoteTag(); } }} placeholder={noteTags.length ? 'Добавить тег…' : 'Введите тег и нажмите Enter'} />
                    </span>
                  </label>
                  <label class="label">
                    <span class="label-text">Заметки</span>
                    <textarea class="textarea notes-textarea" bind:value={noteDescription} rows="8" placeholder="Произвольные заметки о проекте"></textarea>
                  </label>
                </section>
                {:else if projectDetailTab === 'security'}
                <section class="project-tab-content security-content card preset-filled-surface-100-900" aria-label="Безопасность">
                  <label class="security-option">
                    <input class="checkbox" type="checkbox" checked={selectedProject.protected} disabled={securitySaving} onchange={(event) => setProjectProtected(event.currentTarget.checked)} />
                    <span>Защищенный проект</span>
                  </label>
                  <Tooltip positioning={{ placement: 'right' }}>
                    <Tooltip.Trigger class="security-help" aria-label="О защите проекта"><CircleHelp size={18} aria-hidden="true" /></Tooltip.Trigger>
                    <Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">Для защищенных проектов запрещены команды, которые могут изменить их данные - и файлы, и базы данных</Tooltip.Content></Tooltip.Positioner>
                  </Tooltip>
                </section>
                {:else if projectDetailTab === 'backups'}
                  <HttpRefreshBoundary coordinator={pageRefresh} refresh={refreshProjectBackups} />
                <section class="project-log-view backup-view" aria-label={`Бэкапы проекта ${selectedProject.name}`}>
                  <div class="backup-actions-toolbar">
                    <button class="btn preset-filled-primary-500" type="button" onclick={openBackupCreateDialog}><Plus size={16} aria-hidden="true" />Добавить</button>
                  </div>
                  <div class="log-toolbar card preset-filled-surface-100-900">
                    <label><span>Название</span><span class="log-text-filter"><input type="search" value={backupName} oninput={(event) => changeBackupFilter('name', event.currentTarget.value)} />{#if backupName}<button type="button" aria-label="Сбросить название" onclick={() => changeBackupFilter('name', '')}>×</button>{/if}</span></label>
                    <label><span>Состав</span><Combobox collection={backupCompositionCollection} value={[backupComposition]} openOnClick onValueChange={(details) => changeBackupFilter('composition', details.value[0] || 'all')}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly />{#if backupComposition !== 'all'}<button class="log-filter-clear" type="button" aria-label="Сбросить состав" onclick={(event) => { event.stopPropagation(); changeBackupFilter('composition', 'all'); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each backupCompositionOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
                    <label><span>Тип СУБД</span><Combobox collection={backupDatabaseCollection} value={[backupDatabase]} openOnClick onValueChange={(details) => changeBackupFilter('database', details.value[0] || 'all')}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly />{#if backupDatabase !== 'all'}<button class="log-filter-clear" type="button" aria-label="Сбросить тип СУБД" onclick={(event) => { event.stopPropagation(); changeBackupFilter('database', 'all'); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each backupDatabaseOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
                    <label><span>Стратегия</span><Combobox collection={backupStrategyFilterCollection} value={[backupStrategy]} openOnClick onValueChange={(details) => changeBackupFilter('strategy', details.value[0] || 'all')}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly />{#if backupStrategy !== 'all'}<button class="log-filter-clear" type="button" aria-label="Сбросить стратегию" onclick={(event) => { event.stopPropagation(); changeBackupFilter('strategy', 'all'); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each backupStrategyFilterOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
                    <label><span>Расположение</span><Combobox collection={backupLocationFilterCollection} value={[backupLocation]} openOnClick onValueChange={(details) => changeBackupFilter('location', details.value[0] || 'all')}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly />{#if backupLocation !== 'all'}<button class="log-filter-clear" type="button" aria-label="Сбросить расположение" onclick={(event) => { event.stopPropagation(); changeBackupFilter('location', 'all'); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each backupLocationFilterOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
                    <BackupDateFilter label="Дата от" value={backupDateFrom} onchange={(value) => changeBackupFilter('dateFrom', value)} />
                    <BackupDateFilter label="Дата до" value={backupDateTo} onchange={(value) => changeBackupFilter('dateTo', value)} />
                  </div>
                  <div class="log-table-wrap card preset-filled-surface-100-900">
                    <table class="table table-zebra log-table backup-table">
                      <thead><tr><th class="backup-menu-column"><button class="backup-refresh-trigger" type="button" disabled={backupsLoading} aria-label="Обновить список бэкапов" title="Обновить" onclick={loadProjectBackups}><RotateCw size={17} class={backupsLoading ? 'animate-spin' : ''} aria-hidden="true" /></button></th>{#each [['name', 'Название'], ['date', 'Дата'], ['composition', 'Состав'], ['size', 'Размер'], ['database', 'Тип СУБД'], ['strategy', 'Стратегия'], ['location', 'Расположение']] as [field, label]}<th><button type="button" onclick={() => sortBackups(field)}>{label}<span aria-hidden="true">{backupSort === field ? (backupDirection === 'asc' ? ' ↑' : ' ↓') : ' ↕'}</span></button></th>{/each}</tr></thead>
                      <tbody>
                        {#if backupsLoading}<tr><td colspan="8" class="log-empty animate-pulse">Загрузка…</td></tr>
                        {:else if backupItems.length === 0}<tr><td colspan="8" class="log-empty">Бэкапы не найдены</td></tr>
                        {:else}{#each backupItems as item}<tr class:backup-invalid={item.filesValid === false} oncontextmenu={(event) => openBackupContextMenu(event, item)}><td class="backup-menu-column"><button class="backup-menu-trigger" type="button" title="Действия" aria-label={`Действия с бэкапом ${item.name}`} aria-haspopup="menu" onclick={(event) => openBackupContextMenu(event, item)}><Menu size={18} aria-hidden="true" /></button></td><td>{item.name}{#if item.comment}<Tooltip positioning={{ placement: 'right' }}><Tooltip.Trigger class="security-help backup-comment-help" aria-label="Комментарий к бэкапу"><CircleHelp size={17} aria-hidden="true" /></Tooltip.Trigger><Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">{item.comment}</Tooltip.Content></Tooltip.Positioner></Tooltip>{/if}{#if item.filesValid === false}<Tooltip positioning={{ placement: 'right' }}><Tooltip.Trigger class="security-help backup-error-help" aria-label="Почему бэкап повреждён"><CircleHelp size={17} aria-hidden="true" /></Tooltip.Trigger><Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">{item.filesError}</Tooltip.Content></Tooltip.Positioner></Tooltip>{/if}</td><td>{formatQueueDate(item.date)}</td><td>{item.composition}</td><td>{formatBackupSize(item)}</td><td>{item.database || '—'}</td><td>{item.strategy || '—'}</td><td>{item.locationName}</td></tr>{/each}{/if}
                      </tbody>
                    </table>
                  </div>
                  <footer class="log-pagination">
                    <span>{backupTotal ? `${(backupPage - 1) * backupPageSize + 1}–${Math.min(backupPage * backupPageSize, backupTotal)} из ${backupTotal}` : '0 бэкапов'}</span>
                    <div class="log-pagination-controls"><button class="btn btn-sm preset-tonal" type="button" disabled={backupPage === 1 || backupsLoading} onclick={() => { backupPage -= 1; loadProjectBackups(); }}>Назад</button><button class="btn btn-sm preset-tonal" type="button" disabled={backupPage >= Math.ceil(backupTotal / backupPageSize) || backupsLoading} onclick={() => { backupPage += 1; loadProjectBackups(); }}>Вперёд</button></div>
                    <div class="log-page-size" aria-label="Количество бэкапов на странице"><Combobox collection={pageSizeCollection} value={[String(backupPageSize)]} openOnClick onValueChange={(details) => { if (details.value[0]) { backupPageSize = Number(details.value[0]); backupPage = 1; loadProjectBackups(); } }}><Combobox.Control class="page-size-control font-combobox-control"><Combobox.Input class="font-combobox-input" aria-label="Количество бэкапов на странице" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each [25, 50, 100] as value}<Combobox.Item item={{ value: String(value), label: String(value) }} class="font-combobox-item"><Combobox.ItemText>{value}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></div>
                  </footer>
                </section>
                {:else if projectDetailTab === 'scheduler'}
                <section class="project-log-view scheduler-view" aria-label={`Планировщик проекта ${selectedProject.name}`}>
                  <div class="backup-actions-toolbar scheduler-actions"><button class="btn preset-filled-primary-500" type="button" onclick={openScheduleDialog}><Plus size={16} aria-hidden="true" />Добавить</button></div>
                  <div class="log-toolbar scheduler-filter card preset-filled-surface-100-900"><label><span>Команда</span><span class="log-text-filter"><input type="search" placeholder="Поиск по команде" value={scheduleQuery} oninput={(event) => { scheduleQuery = event.currentTarget.value; schedulePage = 1; }} />{#if scheduleQuery}<button type="button" aria-label="Сбросить поиск команды" onclick={() => { scheduleQuery = ''; schedulePage = 1; }}>×</button>{/if}</span></label><label><span>Статус</span><Combobox collection={scheduleStatusCollection} value={[scheduleStatus]} openOnClick onValueChange={(details) => { scheduleStatus = details.value[0] || 'all'; schedulePage = 1; }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly />{#if scheduleStatus !== 'all'}<button class="log-filter-clear" type="button" aria-label="Сбросить статус" onclick={(event) => { event.stopPropagation(); scheduleStatus = 'all'; schedulePage = 1; }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each scheduleStatusOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label></div>
                  <div class="log-table-wrap scheduler-table-wrap card preset-filled-surface-100-900">
                    <table class="table table-zebra log-table scheduler-table"><thead><tr><th class="scheduler-menu-column"><button class="backup-refresh-trigger" type="button" disabled={scheduleLoading} aria-label="Обновить список команд" title="Обновить" onclick={loadSchedule}><RotateCw size={17} class={scheduleLoading ? 'animate-spin' : ''} aria-hidden="true" /></button></th><th>Включено</th><th>Расписание</th><th>Команда</th><th>Рабочая папка</th></tr></thead><tbody>
                      {#if scheduleLoading}<tr><td colspan="5" class="log-empty animate-pulse">Загрузка…</td></tr>
                      {:else if filteredScheduleItems.length === 0}<tr><td colspan="5" class="log-empty">{scheduleQuery || scheduleStatus !== 'all' ? 'Команды не найдены' : 'Запланированных команд пока нет'}</td></tr>
                      {:else}{#each pagedScheduleItems as item}<tr class:schedule-disabled={!item.enabled} oncontextmenu={(event) => openScheduleContextMenu(event, item)}><td class="scheduler-menu-column"><button class="backup-menu-trigger" type="button" aria-label={`Действия с командой ${item.command}`} aria-haspopup="menu" onclick={(event) => openScheduleContextMenu(event, item)}><Menu size={18} aria-hidden="true" /></button></td><td class="scheduler-enabled">{item.enabled ? 'Да' : 'Нет'}</td><td><code>{item.schedule}</code></td><td><code>{item.command}</code></td><td>{item.workingDirectory || '—'}</td></tr>{/each}{/if}
                    </tbody></table>
                  </div>
                  <footer class="log-pagination scheduler-pagination"><span>{filteredScheduleItems.length ? `${(schedulePage - 1) * schedulePageSize + 1}–${Math.min(schedulePage * schedulePageSize, filteredScheduleItems.length)} из ${filteredScheduleItems.length}` : '0 команд'}</span><div class="log-pagination-controls"><button class="btn btn-sm preset-tonal" type="button" disabled={schedulePage === 1 || scheduleLoading} onclick={() => schedulePage -= 1}>Назад</button><button class="btn btn-sm preset-tonal" type="button" disabled={schedulePage >= schedulePageCount || scheduleLoading} onclick={() => schedulePage += 1}>Вперёд</button></div><div class="log-page-size" aria-label="Количество команд на странице"><Combobox collection={pageSizeCollection} value={[String(schedulePageSize)]} openOnClick onValueChange={(details) => { if (details.value[0]) { schedulePageSize = Number(details.value[0]); schedulePage = 1; } }}><Combobox.Control class="page-size-control font-combobox-control"><Combobox.Input class="font-combobox-input" aria-label="Количество команд на странице" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each [25, 50, 100] as value}<Combobox.Item item={{ value: String(value), label: String(value) }} class="font-combobox-item"><Combobox.ItemText>{value}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></div></footer>
                </section>
                {:else}
                  <HttpRefreshBoundary coordinator={pageRefresh} refresh={loadLogs} />
                <section class="project-log-view" aria-label={`Журнал проекта ${selectedProject.name}`}>
                  <div class="log-toolbar card preset-filled-surface-100-900">
                    <label>
                      <span>Тип записи</span>
                      <Combobox collection={logTypeCollection} value={[specificSelections(logType)[0] || 'queue']} openOnClick onValueChange={(details) => applyLogSelection('type', details.value)}>
                        <Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" value={logSelectionLabel(logTypes, logType)} readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control>
                        <Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each logTypes as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner>
                      </Combobox>
                    </label>
                    <label>
                      <span>Статус</span>
                      <Combobox collection={logStatusCollection} value={logStatus} multiple openOnClick onValueChange={(details) => applyLogSelection('status', details.value)}>
                        <Combobox.Control class="font-combobox-control status-combobox-control">{#if specificSelections(logStatus).length === 1}<span class={`queue-dot status-${specificSelections(logStatus)[0]}`} aria-hidden="true"></span>{/if}<Combobox.Input class="font-combobox-input" value={logSelectionLabel(logStatuses, logStatus)} readonly />{#if specificSelections(logStatus).length}<button class="log-filter-clear" type="button" aria-label="Сбросить статус" onclick={(event) => { event.stopPropagation(); applyLogSelection('status', ['all']); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control>
                        <Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each logStatuses as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText><span class="log-status-option">{#if item.value !== 'all'}<span class={`queue-dot status-${item.value}`} aria-hidden="true"></span>{/if}{item.label}</span></Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner>
                      </Combobox>
                    </label>
                    {#each logCategoryFilters as filter}
                      <label><span>{filter.label}</span><Combobox collection={filter.collection} value={filter.field === 'level' ? logLevel : logContext} multiple openOnClick onValueChange={(details) => applyLogSelection(filter.field, details.value)}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" value={logSelectionLabel(filter.items, filter.field === 'level' ? logLevel : logContext)} readonly />{#if specificSelections(filter.field === 'level' ? logLevel : logContext).length}<button class="log-filter-clear" type="button" aria-label={`Сбросить ${filter.label.toLocaleLowerCase()}`} onclick={(event) => { event.stopPropagation(); applyLogSelection(filter.field, ['all']); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each filter.items as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText><span class={filter.field === 'level' && item.value !== 'all' ? `log-level level-${item.value}` : ''}>{item.label}</span></Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
                    {/each}
                    {#each [['queueItem', 'Элемент очереди', logQueueItem], ['itemCode', 'Код элемента', logItemCode], ['taskCode', 'Задача', logTaskCode]] as [field, label, value]}
                      <label><span>{label}</span><span class="log-text-filter"><input value={value} oninput={(event) => changeTextLogFilter(field, event.currentTarget.value)} />{#if value}<button type="button" aria-label={`Сбросить фильтр «${label}»`} onclick={() => changeTextLogFilter(field, '')}>×</button>{/if}</span></label>
                    {/each}
                  </div>
                  <div class="log-table-wrap card preset-filled-surface-100-900">
                    <table class="table table-zebra log-table project-log-table">
                      <thead><tr>{#each [['timestamp', 'Время'], ['queueItem', 'Элемент очереди'], ['itemCode', 'Код элемента'], ['queueCode', 'Очередь'], ['status', 'Статус'], ['taskCode', 'Задача'], ['level', 'Уровень'], ['context', 'Контекст'], ['result', 'Результат'], ['message', 'Сообщение']] as [field, label]}<th><button type="button" onclick={() => sortLogs(field)}>{label}<span aria-hidden="true">{logSort === field ? (logDirection === 'asc' ? ' ↑' : ' ↓') : ' ↕'}</span></button></th>{/each}</tr></thead>
                      <tbody>
                        {#if logsLoading}<tr><td colspan="10" class="log-empty animate-pulse">Загрузка…</td></tr>
                        {:else if logItems.length === 0}<tr><td colspan="10" class="log-empty">Записей нет</td></tr>
                        {:else}{#each logItems as item}<tr><td>{formatQueueDate(item.timestamp)}</td><td><button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('queueItem', item.queueItem)}>{formatLogValue(item.queueItem)}</button></td><td><button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('itemCode', item.itemCode)}>{formatLogValue(item.itemCode)}</button></td><td>{formatLogValue(item.queueCode)}</td><td class="log-nowrap">{#if item.status}<button class="log-filter-link log-status-link" type="button" onclick={() => changeLogStatus(item.status)}><span class={`queue-dot status-${item.status}`} aria-hidden="true"></span>{logStatusLabel(item.status)}</button>{:else}—{/if}</td><td>{#if item.taskCode}<button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('taskCode', item.taskCode)}>{item.taskCode}</button>{:else}—{/if}</td><td class="log-nowrap">{#if item.level}<button class={`log-filter-link log-level level-${item.level}`} type="button" onclick={() => changeLogCategory('level', item.level)}>{logCategoryLabel(logLevels, item.level)}</button>{:else}—{/if}</td><td class="log-nowrap">{#if item.context}<button class="log-filter-link" type="button" onclick={() => changeLogCategory('context', item.context)}>{logCategoryLabel(logContexts, item.context)}</button>{:else}—{/if}</td><td>{formatLogValue(item.result)}</td><td>{formatLogValue(item.message)}</td></tr>{/each}{/if}
                      </tbody>
                    </table>
                  </div>
                  <footer class="log-pagination">
                    <span>{logTotal ? `${(logPage - 1) * logPageSize + 1}–${Math.min(logPage * logPageSize, logTotal)} из ${logTotal}` : '0 записей'}</span>
                    <div class="log-pagination-controls"><button class="btn btn-sm preset-tonal" type="button" disabled={logPage === 1 || logsLoading} onclick={() => changeLogPage(logPage - 1)}>Назад</button><button class="btn btn-sm preset-tonal" type="button" disabled={logPage >= Math.ceil(logTotal / logPageSize) || logsLoading} onclick={() => changeLogPage(logPage + 1)}>Вперёд</button></div>
                    <div class="log-page-size" aria-label="Количество записей на странице"><Combobox collection={pageSizeCollection} value={[String(logPageSize)]} openOnClick onValueChange={(details) => details.value[0] && changeLogPageSize(details.value[0])}><Combobox.Control class="page-size-control font-combobox-control"><Combobox.Input class="font-combobox-input" aria-label="Количество записей на странице" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each [25, 50, 100] as value}<Combobox.Item item={{ value: String(value), label: String(value) }} class="font-combobox-item"><Combobox.ItemText>{value}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></div>
                  </footer>
                </section>
                {/if}
              </div>
            {:else}
              <div class="select-project">Выберите проект</div>
            {/if}
          </div>
        </div>
        {:else if activeSection === 'logs'}
          <HttpRefreshBoundary coordinator={pageRefresh} refresh={loadLogs} />
          <section class="log-view" aria-label="Журнал">
            <div class="log-toolbar card preset-filled-surface-100-900">
              <label>
                <span>Тип записи</span>
                <Combobox collection={logTypeCollection} value={[specificSelections(logType)[0] || 'queue']} openOnClick onValueChange={(details) => applyLogSelection('type', details.value)}>
                  <Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" value={logSelectionLabel(logTypes, logType)} readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control>
                  <Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each logTypes as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner>
                </Combobox>
              </label>
              <label>
                <span>Проект</span>
                <Combobox collection={logProjectCollection} value={logProject} multiple openOnClick onValueChange={(details) => applyLogSelection('project', details.value)}>
                  <Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" value={logSelectionLabel([{ value: 'all', label: 'Все проекты' }, ...logProjects.map((value) => ({ value, label: value }))], logProject)} readonly />{#if specificSelections(logProject).length}<button class="log-filter-clear" type="button" aria-label="Сбросить проект" onclick={(event) => { event.stopPropagation(); applyLogSelection('project', ['all']); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control>
                  <Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each [{ value: 'all', label: 'Все проекты' }, ...logProjects.map((value) => ({ value, label: value }))] as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner>
                </Combobox>
              </label>
              {#if isHookLog}
                <label><span>Уровень</span><Combobox collection={logLevelCollection} value={logLevel} multiple openOnClick onValueChange={(details) => applyLogSelection('level', details.value)}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" value={logSelectionLabel(logLevels, logLevel)} readonly />{#if specificSelections(logLevel).length}<button class="log-filter-clear" type="button" aria-label="Сбросить уровень" onclick={(event) => { event.stopPropagation(); applyLogSelection('level', ['all']); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each logLevels as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText><span class={item.value !== 'all' ? `log-level level-${item.value}` : ''}>{item.label}</span></Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
                <label><span>Уровень хука</span><Combobox collection={hookLevelCollection} value={[logHookLevel || 'all']} openOnClick onValueChange={(details) => { logHookLevel = details.value[0] === 'command' ? 'command' : ''; logPage = 1; syncJournalFilters(false); loadLogs(); }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly />{#if logHookLevel}<button class="log-filter-clear" type="button" aria-label="Сбросить уровень хука" onclick={(event) => { event.stopPropagation(); logHookLevel = ''; logPage = 1; syncJournalFilters(false); loadLogs(); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each hookLevelOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
                <label><span>Время выполнения</span><Combobox collection={hookTimingCollection} value={[logTiming || 'all']} openOnClick onValueChange={(details) => { logTiming = ['before', 'after'].includes(details.value[0]) ? details.value[0] : ''; logPage = 1; syncJournalFilters(false); loadLogs(); }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly />{#if logTiming}<button class="log-filter-clear" type="button" aria-label="Сбросить время выполнения" onclick={(event) => { event.stopPropagation(); logTiming = ''; logPage = 1; syncJournalFilters(false); loadLogs(); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each hookTimingOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
                {#each [['command', 'Команда', logCommand], ['hook', 'Хук', logHook]] as [field, label, value]}
                  <label><span>{label}</span><span class="log-text-filter"><input value={value} oninput={(event) => changeTextLogFilter(field, event.currentTarget.value)} />{#if value}<button type="button" aria-label={`Сбросить фильтр «${label}»`} onclick={() => changeTextLogFilter(field, '')}>×</button>{/if}</span></label>
                {/each}
              {:else}
                <label><span>Статус</span><Combobox collection={logStatusCollection} value={logStatus} multiple openOnClick onValueChange={(details) => applyLogSelection('status', details.value)}><Combobox.Control class="font-combobox-control status-combobox-control">{#if specificSelections(logStatus).length === 1}<span class={`queue-dot status-${specificSelections(logStatus)[0]}`} aria-hidden="true"></span>{/if}<Combobox.Input class="font-combobox-input" value={logSelectionLabel(logStatuses, logStatus)} readonly />{#if specificSelections(logStatus).length}<button class="log-filter-clear" type="button" aria-label="Сбросить статус" onclick={(event) => { event.stopPropagation(); applyLogSelection('status', ['all']); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each logStatuses as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText><span class="log-status-option">{#if item.value !== 'all'}<span class={`queue-dot status-${item.value}`} aria-hidden="true"></span>{/if}{item.label}</span></Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
                {#each logCategoryFilters as filter}<label><span>{filter.label}</span><Combobox collection={filter.collection} value={filter.field === 'level' ? logLevel : logContext} multiple openOnClick onValueChange={(details) => applyLogSelection(filter.field, details.value)}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" value={logSelectionLabel(filter.items, filter.field === 'level' ? logLevel : logContext)} readonly />{#if specificSelections(filter.field === 'level' ? logLevel : logContext).length}<button class="log-filter-clear" type="button" aria-label={`Сбросить ${filter.label.toLocaleLowerCase()}`} onclick={(event) => { event.stopPropagation(); applyLogSelection(filter.field, ['all']); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each filter.items as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText><span class={filter.field === 'level' && item.value !== 'all' ? `log-level level-${item.value}` : ''}>{item.label}</span></Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>{/each}
                {#each [['queueItem', 'Элемент очереди', logQueueItem], ['itemCode', 'Код элемента', logItemCode], ['taskCode', 'Задача', logTaskCode]] as [field, label, value]}<label><span>{label}</span><span class="log-text-filter"><input value={value} oninput={(event) => changeTextLogFilter(field, event.currentTarget.value)} />{#if value}<button type="button" aria-label={`Сбросить фильтр «${label}»`} onclick={() => changeTextLogFilter(field, '')}>×</button>{/if}</span></label>{/each}
              {/if}
            </div>
            <div class="log-table-wrap card preset-filled-surface-100-900">
              <table class="table table-zebra log-table">
                <thead><tr>
                  {#each (isHookLog ? [['project', 'Проект'], ['timestamp', 'Время'], ['hook', 'Хук'], ['command', 'Код команды'], ['timing', 'Время выполнения'], ['hookLevel', 'Уровень хука'], ['level', 'Уровень'], ['message', 'Сообщение']] : [['timestamp', 'Время'], ['queueItem', 'Элемент очереди'], ['itemCode', 'Код элемента'], ['project', 'Проект'], ['queueCode', 'Очередь'], ['status', 'Статус'], ['taskCode', 'Задача'], ['level', 'Уровень'], ['context', 'Контекст'], ['result', 'Результат'], ['message', 'Сообщение']]) as [field, label]}
                    <th><button type="button" onclick={() => sortLogs(field)}>{label}<span aria-hidden="true">{logSort === field ? (logDirection === 'asc' ? ' ↑' : ' ↓') : ' ↕'}</span></button></th>
                  {/each}
                </tr></thead>
                <tbody>
                  {#if logsLoading}<tr><td colspan={isHookLog ? 8 : 11} class="log-empty animate-pulse">Загрузка…</td></tr>
                  {:else if logItems.length === 0}<tr><td colspan={isHookLog ? 8 : 11} class="log-empty">Записей нет</td></tr>
                  {:else if isHookLog}{#each logItems as item}<tr><td>{#if logRecordProjects(item).length}{#each logRecordProjects(item) as project, index}{#if index}, {/if}<button class="log-filter-link" type="button" onclick={() => changeLogProject(project)}>{project}</button>{/each}{:else}—{/if}</td><td>{formatQueueDate(item.timestamp)}</td><td><button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('hook', item.hook)}>{formatLogValue(item.hook)}</button></td><td><button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('command', item.command)}>{formatLogValue(item.command)}</button></td><td><button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('timing', item.timing)}>{formatLogValue(item.timing)}</button></td><td>{item.hookLevel === 'command' ? 'Команда' : formatLogValue(item.hookLevel)}</td><td class="log-nowrap">{#if item.level}<button class={`log-filter-link log-level level-${item.level}`} type="button" onclick={() => changeLogCategory('level', item.level)}>{logCategoryLabel(logLevels, item.level)}</button>{:else}—{/if}</td><td>{formatLogValue(item.message)}</td></tr>{/each}
                  {:else}{#each logItems as item}<tr><td>{formatQueueDate(item.timestamp)}</td><td><button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('queueItem', item.queueItem)}>{formatLogValue(item.queueItem)}</button></td><td><button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('itemCode', item.itemCode)}>{formatLogValue(item.itemCode)}</button></td><td>{#if logRecordProjects(item).length}{#each logRecordProjects(item) as project, index}{#if index}, {/if}<button class="log-filter-link" type="button" onclick={() => changeLogProject(project)}>{project}</button>{/each}{:else}—{/if}</td><td>{formatLogValue(item.queueCode)}</td><td class="log-nowrap">{#if item.status}<button class="log-filter-link log-status-link" type="button" onclick={() => changeLogStatus(item.status)}><span class={`queue-dot status-${item.status}`} aria-hidden="true"></span>{logStatusLabel(item.status)}</button>{:else}—{/if}</td><td>{#if item.taskCode}<button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('taskCode', item.taskCode)}>{item.taskCode}</button>{:else}—{/if}</td><td class="log-nowrap">{#if item.level}<button class={`log-filter-link log-level level-${item.level}`} type="button" onclick={() => changeLogCategory('level', item.level)}>{logCategoryLabel(logLevels, item.level)}</button>{:else}—{/if}</td><td class="log-nowrap">{#if item.context}<button class="log-filter-link" type="button" onclick={() => changeLogCategory('context', item.context)}>{logCategoryLabel(logContexts, item.context)}</button>{:else}—{/if}</td><td>{formatLogValue(item.result)}</td><td>{formatLogValue(item.message)}</td></tr>{/each}{/if}
                </tbody>
              </table>
            </div>
            <footer class="log-pagination">
              <span>{logTotal ? `${(logPage - 1) * logPageSize + 1}–${Math.min(logPage * logPageSize, logTotal)} из ${logTotal}` : '0 записей'}</span>
              <div class="log-pagination-controls">
                <button class="btn btn-sm preset-tonal" type="button" disabled={logPage === 1 || logsLoading} onclick={() => changeLogPage(logPage - 1)}>Назад</button>
                <button class="btn btn-sm preset-tonal" type="button" disabled={logPage >= Math.ceil(logTotal / logPageSize) || logsLoading} onclick={() => changeLogPage(logPage + 1)}>Вперёд</button>
              </div>
              <div class="log-page-size" aria-label="Количество записей на странице"><Combobox collection={pageSizeCollection} value={[String(logPageSize)]} openOnClick onValueChange={(details) => details.value[0] && changeLogPageSize(details.value[0])}><Combobox.Control class="page-size-control font-combobox-control"><Combobox.Input class="font-combobox-input" aria-label="Количество записей на странице" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each [25, 50, 100] as value}<Combobox.Item item={{ value: String(value), label: String(value) }} class="font-combobox-item"><Combobox.ItemText>{value}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></div>
            </footer>
          </section>
        {:else if activeSection === 'settings'}
          <section class="settings-view" aria-label="Настройки">
            <nav class="project-detail-tabs settings-tabs" aria-label="Разделы настроек">
              <a class:active={settingsTab === 'projects'} class="project-detail-tab" href="#/settings/projects" aria-current={settingsTab === 'projects' ? 'page' : undefined}>Проекты</a>
              <a class:active={settingsTab === 'backups'} class="project-detail-tab" href="#/settings/backups" aria-current={settingsTab === 'backups' ? 'page' : undefined}>Бэкапы</a>
              <a class:active={settingsTab === 'users'} class="project-detail-tab" href="#/settings/users" aria-current={settingsTab === 'users' ? 'page' : undefined}>Пользователи</a>
              <a class:active={settingsTab === 'hooks'} class="project-detail-tab" href="#/settings/hooks" aria-current={settingsTab === 'hooks' ? 'page' : undefined}>Хуки</a>
              <a class:active={settingsTab === 'security'} class="project-detail-tab" href="#/settings/security" aria-current={settingsTab === 'security' ? 'page' : undefined}>Безопасность</a>
            </nav>
            {#if settingsTab === 'projects'}
              <HttpRefreshBoundary coordinator={pageRefresh} refresh={loadProjectsSettings} />
            <div class="settings-scroll">
              <div class="project-toolbar">
                <button class="btn preset-filled-primary-500" type="button" disabled={projectSettingsLoading || projectSettingsSaving || projectLocations.some((location) => !location.path.trim()) || projectDatabaseLocations.some((location) => !location.path.trim())} onclick={saveProjectLocations}>
                  <Save size={16} aria-hidden="true" />{projectSettingsSaving ? 'Сохраняем…' : 'Сохранить'}
                </button>
              </div>
              <section class="settings-card locations-card card preset-filled-surface-100-900" aria-label="Расположения файлов">
                <h2>Расположения файлов
                  <Tooltip positioning={{ placement: 'right' }}>
                    <Tooltip.Trigger class="security-help" aria-label="О расположениях проектов"><CircleHelp size={18} aria-hidden="true" /></Tooltip.Trigger>
                    <Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">Эти пути будут использоваться в скриптах автоматической развертки проектов. Пока не добавлен хотя бы один путь, функционал автоматической развертки не заработает</Tooltip.Content></Tooltip.Positioner>
                  </Tooltip>
                </h2>
                <div class="location-list">
                  {#each projectLocations as location, index}
                    <div class="location-item">
                      <div class="location-row">
                        <input class="input location-path" type="text" value={location.path} disabled={projectSettingsLoading || projectSettingsSaving} placeholder="/путь/к/проектам" aria-label={`Расположение проектов ${index + 1}`} oninput={(event) => updateProjectLocation(index, event.currentTarget.value)} />
                        <input class="input location-code" type="text" value={location.code} disabled={projectSettingsLoading || projectSettingsSaving} placeholder="код (автоматически)" aria-label={`Код расположения ${index + 1}`} oninput={(event) => updateProjectLocationCode(index, event.currentTarget.value)} />
                        <button class="btn preset-tonal" type="button" title="Добавить расположение" aria-label="Добавить расположение" disabled={!location.path.trim() || projectSettingsLoading || projectSettingsSaving} onclick={addProjectLocation}><Plus size={16} aria-hidden="true" /></button>
                        {#if projectLocations.length > 1}<button class="btn preset-tonal location-delete" type="button" title="Удалить расположение" aria-label="Удалить расположение" disabled={projectSettingsLoading || projectSettingsSaving} onclick={() => removeProjectLocation(index)}><Trash2 size={16} aria-hidden="true" /></button>{/if}
                        <input class="radio location-default" type="radio" name="default-project-location" checked={location.default} disabled={projectSettingsLoading || projectSettingsSaving} aria-label="Путь по умолчанию" onchange={() => setDefaultProjectLocation(index)} />
                        <Tooltip positioning={{ placement: 'right' }}>
                          <Tooltip.Trigger class="security-help location-default-help" aria-label="О пути по умолчанию"><CircleHelp size={18} aria-hidden="true" /></Tooltip.Trigger>
                          <Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">Путь для автоматической развертки проектов по умолчанию</Tooltip.Content></Tooltip.Positioner>
                        </Tooltip>
                      </div>
                    </div>
                  {/each}
                </div>
              </section>
              <section class="settings-card locations-card card preset-filled-surface-100-900" aria-label="Расположение БД">
                <h2>Расположение БД
                  <Tooltip positioning={{ placement: 'right' }}>
                    <Tooltip.Trigger class="security-help" aria-label="О расположениях БД"><CircleHelp size={18} aria-hidden="true" /></Tooltip.Trigger>
                    <Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">Каталоги для хранения данных выделенных экземпляров MySQL и PostgreSQL</Tooltip.Content></Tooltip.Positioner>
                  </Tooltip>
                </h2>
                <div class="location-list">
                  {#each projectDatabaseLocations as location, index}
                    <div class="location-item"><div class="location-row">
                      <input class="input location-path" type="text" value={location.path} disabled={projectSettingsLoading || projectSettingsSaving} placeholder="/путь/к/базам-данных" aria-label={`Расположение БД ${index + 1}`} oninput={(event) => updateProjectDatabaseLocation(index, 'path', event.currentTarget.value)} />
                      <input class="input location-code" type="text" value={location.code} disabled={projectSettingsLoading || projectSettingsSaving} placeholder="код (автоматически)" aria-label={`Код расположения БД ${index + 1}`} oninput={(event) => updateProjectDatabaseLocation(index, 'code', event.currentTarget.value)} />
                      <button class="btn preset-tonal" type="button" title="Добавить расположение БД" aria-label="Добавить расположение БД" disabled={!location.path.trim() || projectSettingsLoading || projectSettingsSaving} onclick={addProjectDatabaseLocation}><Plus size={16} aria-hidden="true" /></button>
                      {#if projectDatabaseLocations.length > 1}<button class="btn preset-tonal location-delete" type="button" title="Удалить расположение БД" aria-label="Удалить расположение БД" disabled={projectSettingsLoading || projectSettingsSaving} onclick={() => removeProjectDatabaseLocation(index)}><Trash2 size={16} aria-hidden="true" /></button>{/if}
                      <input class="checkbox location-default" type="checkbox" checked={location.default} disabled={projectSettingsLoading || projectSettingsSaving} aria-label="Путь БД по умолчанию" onchange={() => setDefaultProjectDatabaseLocation(index)} />
                    </div></div>
                  {/each}
                </div>
              </section>
            </div>
            {:else if settingsTab === 'backups'}
              <HttpRefreshBoundary coordinator={pageRefresh} refresh={loadBackupsSettings} />
            <div class="settings-scroll">
              <div class="project-toolbar">
                <button class="btn preset-filled-primary-500" type="button" disabled={backupSettingsLoading || backupSettingsSaving || backupLocations.some((location) => !location.path.trim()) || backupFileStrategies.some((strategy) => !strategy.name.trim())} onclick={saveBackupLocations}>
                  <Save size={16} aria-hidden="true" />{backupSettingsSaving ? 'Сохраняем…' : 'Сохранить'}
                </button>
              </div>
              <section class="settings-card locations-card card preset-filled-surface-100-900" aria-label="Расположение">
                <h2>Расположение
                  <Tooltip positioning={{ placement: 'right' }}>
                    <Tooltip.Trigger class="security-help" aria-label="О расположениях бэкапов"><CircleHelp size={18} aria-hidden="true" /></Tooltip.Trigger>
                    <Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">Расположение централизованных хранилищ проектных бэкапов</Tooltip.Content></Tooltip.Positioner>
                  </Tooltip>
                </h2>
                <div class="location-list">
                  {#each backupLocations as location, index}
                    <div class="location-item">
                      <div class="location-row">
                        <input class="input location-path" type="text" value={location.path} disabled={backupSettingsLoading || backupSettingsSaving} placeholder="/путь/к/бэкапам" aria-label={`Расположение бэкапов ${index + 1}`} oninput={(event) => updateBackupLocation(index, 'path', event.currentTarget.value)} />
                        <input class="input location-code" type="text" value={location.code} disabled={backupSettingsLoading || backupSettingsSaving} placeholder="код (автоматически)" aria-label={`Код расположения ${index + 1}`} oninput={(event) => updateBackupLocation(index, 'code', event.currentTarget.value)} />
                        <button class="btn preset-tonal" type="button" title="Добавить расположение" aria-label="Добавить расположение" disabled={!location.path.trim() || backupSettingsLoading || backupSettingsSaving} onclick={addBackupLocation}><Plus size={16} aria-hidden="true" /></button>
                        {#if backupLocations.length > 1}<button class="btn preset-tonal location-delete" type="button" title="Удалить расположение" aria-label="Удалить расположение" disabled={backupSettingsLoading || backupSettingsSaving} onclick={() => removeBackupLocation(index)}><Trash2 size={16} aria-hidden="true" /></button>{/if}
                        <input class="radio location-default" type="radio" name="default-backup-location" checked={location.default} disabled={backupSettingsLoading || backupSettingsSaving} aria-label="Путь по умолчанию" onchange={() => setDefaultBackupLocation(index)} />
                        <Tooltip positioning={{ placement: 'right' }}>
                          <Tooltip.Trigger class="security-help location-default-help" aria-label="О пути по умолчанию"><CircleHelp size={18} aria-hidden="true" /></Tooltip.Trigger>
                          <Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">Централизованное хранилище проектных бэкапов по умолчанию</Tooltip.Content></Tooltip.Positioner>
                        </Tooltip>
                      </div>
                    </div>
                  {/each}
                </div>
              </section>
              <section class="settings-card locations-card card preset-filled-surface-100-900" aria-label="Стратегии">
                <h2>Стратегии
                  <Tooltip positioning={{ placement: 'right' }}>
                    <Tooltip.Trigger class="security-help" aria-label="О стратегиях"><CircleHelp size={18} aria-hidden="true" /></Tooltip.Trigger>
                    <Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">Стратегии, определяющие состав файлов и таблиц в бэкапах проектов</Tooltip.Content></Tooltip.Positioner>
                  </Tooltip>
                </h2>
                <div class="location-list">
                  {#each backupFileStrategies as strategy, index}
                    <div class="location-item"><div class="location-row">
                      <input class="input location-path" type="text" value={strategy.name} disabled={backupSettingsLoading || backupSettingsSaving} placeholder="название стратегии" aria-label={`Название стратегии ${index + 1}`} oninput={(event) => updateFileStrategy(index, 'name', event.currentTarget.value)} />
                      <input class="input location-code" type="text" value={strategy.code} disabled={backupSettingsLoading || backupSettingsSaving} placeholder="код (автоматически)" aria-label={`Код стратегии ${index + 1}`} oninput={(event) => updateFileStrategy(index, 'code', event.currentTarget.value)} />
                      <button class="btn preset-tonal" type="button" title="Настройки" aria-label={`Настройки стратегии ${index + 1}`} disabled={backupSettingsLoading || backupSettingsSaving} onclick={() => openFileStrategySettings(index)}><Settings size={16} aria-hidden="true" /></button>
                      <button class="btn preset-tonal" type="button" title="Добавить стратегию" aria-label="Добавить стратегию" disabled={!strategy.name.trim() || backupSettingsLoading || backupSettingsSaving} onclick={addFileStrategy}><Plus size={16} aria-hidden="true" /></button>
                      <button class="btn preset-tonal location-delete" type="button" title="Удалить стратегию" aria-label="Удалить стратегию" disabled={backupSettingsLoading || backupSettingsSaving} onclick={() => removeFileStrategy(index)}><Trash2 size={16} aria-hidden="true" /></button>
                    </div></div>
                  {/each}
                </div>
              </section>
            </div>
            {:else if settingsTab === 'users'}
              <HttpRefreshBoundary coordinator={pageRefresh} refresh={loadUsersSettings} />
            <div class="settings-scroll users-settings-scroll">
              <div class="project-toolbar">
                <button class="btn preset-filled-primary-500" type="button" onclick={() => { userDialog = { create: true, login: '', comments: '' }; }}><Plus size={16} aria-hidden="true" />Добавить</button>
              </div>
              <div class="users-table-wrap card preset-filled-surface-100-900">
                <table class="table table-zebra users-table">
                  <thead><tr><th class="user-menu-column" aria-label="Действия"></th><th>Логин</th><th>Комментарии</th></tr></thead>
                  <tbody>
                    {#if usersLoading}<tr><td colspan="3" class="log-empty animate-pulse">Загрузка…</td></tr>
                    {:else if users.length === 0}<tr><td colspan="3" class="log-empty">Пользователей нет</td></tr>
                    {:else}{#each users as user (user.login)}
                      <tr oncontextmenu={(event) => openUserContextMenu(event, user)}>
                        <td class="user-menu-column"><button class="backup-menu-trigger" type="button" title="Действия" aria-label={`Действия с пользователем ${user.login}`} aria-haspopup="menu" onclick={(event) => openUserContextMenu(event, user)}><Menu size={18} aria-hidden="true" /></button></td>
                        <td>{user.login}</td><td>{user.comments || '—'}</td>
                      </tr>
                    {/each}{/if}
                  </tbody>
                </table>
              </div>
              <footer class="log-pagination users-pagination">
                <span>{usersTotal ? `${(usersPage - 1) * usersPageSize + 1}–${Math.min(usersPage * usersPageSize, usersTotal)} из ${usersTotal}` : '0 пользователей'}</span>
                <div class="log-pagination-controls"><button class="btn btn-sm preset-tonal" type="button" disabled={usersPage === 1 || usersLoading} onclick={() => { usersPage -= 1; loadUsersSettings(); }}>Назад</button><button class="btn btn-sm preset-tonal" type="button" disabled={usersPage >= Math.ceil(usersTotal / usersPageSize) || usersLoading} onclick={() => { usersPage += 1; loadUsersSettings(); }}>Вперёд</button></div>
                <div class="log-page-size"><Combobox collection={pageSizeCollection} value={[String(usersPageSize)]} openOnClick onValueChange={(details) => { if (details.value[0]) { usersPageSize = Number(details.value[0]); usersPage = 1; loadUsersSettings(); } }}><Combobox.Control class="page-size-control font-combobox-control"><Combobox.Input class="font-combobox-input" aria-label="Количество пользователей на странице" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each [25, 50, 100] as value}<Combobox.Item item={{ value: String(value), label: String(value) }} class="font-combobox-item"><Combobox.ItemText>{value}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></div>
              </footer>
            </div>
            {:else if settingsTab === 'hooks'}
              <HttpRefreshBoundary coordinator={pageRefresh} refresh={loadHooksSettings} />
            <div class="settings-scroll hooks-settings-scroll">
              <div class="project-toolbar"><button class="btn preset-filled-primary-500" type="button" disabled={hooksLoading} onclick={openHookCreateDialog}><Plus size={16} aria-hidden="true" />Добавить</button></div>
              <div class="log-toolbar hooks-filter card preset-filled-surface-100-900">
                <label><span>Уровень</span><Combobox collection={hookLevelCollection} value={[hookLevel]} openOnClick onValueChange={(details) => changeHookFilter('level', details.value[0] || 'all')}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly />{#if hookLevel !== 'all'}<button class="log-filter-clear" type="button" aria-label="Сбросить уровень" onclick={(event) => { event.stopPropagation(); changeHookFilter('level', 'all'); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each hookLevelOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
                <label><span>Время выполнения</span><Combobox collection={hookTimingCollection} value={[hookTiming]} openOnClick onValueChange={(details) => changeHookFilter('timing', details.value[0] || 'all')}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly />{#if hookTiming !== 'all'}<button class="log-filter-clear" type="button" aria-label="Сбросить время выполнения" onclick={(event) => { event.stopPropagation(); changeHookFilter('timing', 'all'); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each hookTimingOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
                <label><span>Включен</span><Combobox collection={hookEnabledCollection} value={[hookEnabled]} openOnClick onValueChange={(details) => changeHookFilter('enabled', details.value[0] || 'all')}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly />{#if hookEnabled !== 'all'}<button class="log-filter-clear" type="button" aria-label="Сбросить включенность" onclick={(event) => { event.stopPropagation(); changeHookFilter('enabled', 'all'); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each hookEnabledOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
                <label><span>Команда</span><Combobox collection={hookCommandCollection} value={[hookCommandQuery || 'all']} openOnClick onValueChange={(details) => changeHookFilter('command', details.value[0] === 'all' ? '' : (details.value[0] || ''))}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly />{#if hookCommandQuery}<button class="log-filter-clear" type="button" aria-label="Сбросить команду" onclick={(event) => { event.stopPropagation(); changeHookFilter('command', ''); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each hookCommandFilterOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
                <label><span>Хук</span><span class="log-text-filter"><input type="text" value={hookNameQuery} placeholder="имя файла" oninput={(event) => changeHookFilter('hook', event.currentTarget.value)} />{#if hookNameQuery}<button type="button" aria-label="Сбросить хук" onclick={() => changeHookFilter('hook', '')}>×</button>{/if}</span></label>
              </div>
              <div class="log-table-wrap hooks-table-wrap card preset-filled-surface-100-900">
                <table class="table table-zebra log-table hooks-table"><thead><tr><th class="scheduler-menu-column"><button class="backup-refresh-trigger" type="button" disabled={hooksLoading} aria-label="Обновить список хуков" title="Обновить" onclick={loadHooksSettings}><RotateCw size={17} class={hooksLoading ? 'animate-spin' : ''} aria-hidden="true" /></button></th>{#each [['level', 'Уровень'], ['command', 'Код команды'], ['timing', 'Время выполнения'], ['enabled', 'Включен'], ['hook', 'Хук']] as [field, label]}<th><button type="button" onclick={() => sortHooks(field)}>{label}<span aria-hidden="true">{hookSort === field ? (hookDirection === 'asc' ? ' ↑' : ' ↓') : ' ↕'}</span></button></th>{/each}</tr></thead><tbody>
                  {#if hooksLoading}<tr><td colspan="6" class="log-empty animate-pulse">Загрузка…</td></tr>
                  {:else if filteredHooks.length === 0}<tr><td colspan="6" class="log-empty">Хуки не найдены</td></tr>
                  {:else}{#each pagedHooks as hook (hook.id)}<tr class:schedule-disabled={!hook.enabled} oncontextmenu={(event) => openHookContextMenu(event, hook)}><td class="scheduler-menu-column"><button class="backup-menu-trigger" type="button" aria-label={`Действия с хуком ${hook.hook}`} aria-haspopup="menu" onclick={(event) => openHookContextMenu(event, hook)}><Menu size={18} aria-hidden="true" /></button></td><td>{hook.level === 'command' ? 'Команда' : hook.level}</td><td><code>{hook.command}</code></td><td><code>{hook.timing}</code></td><td class="scheduler-enabled">{hook.enabled ? 'Да' : 'Нет'}</td><td><code>{hook.hook}</code></td></tr>{/each}{/if}
                </tbody></table>
              </div>
              <footer class="log-pagination scheduler-pagination hooks-pagination"><span>{filteredHooks.length ? `${(hookPage - 1) * hookPageSize + 1}–${Math.min(hookPage * hookPageSize, filteredHooks.length)} из ${filteredHooks.length}` : '0 хуков'}</span><div class="log-pagination-controls"><button class="btn btn-sm preset-tonal" type="button" disabled={hookPage === 1 || hooksLoading} onclick={() => hookPage -= 1}>Назад</button><button class="btn btn-sm preset-tonal" type="button" disabled={hookPage >= hookPageCount || hooksLoading} onclick={() => hookPage += 1}>Вперёд</button></div><div class="log-page-size" aria-label="Количество хуков на странице"><Combobox collection={pageSizeCollection} value={[String(hookPageSize)]} openOnClick onValueChange={(details) => { if (details.value[0]) { hookPageSize = Number(details.value[0]); hookPage = 1; } }}><Combobox.Control class="page-size-control font-combobox-control"><Combobox.Input class="font-combobox-input" aria-label="Количество хуков на странице" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each [25, 50, 100] as value}<Combobox.Item item={{ value: String(value), label: String(value) }} class="font-combobox-item"><Combobox.ItemText>{value}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></div></footer>
            </div>
            {:else}
              <HttpRefreshBoundary coordinator={pageRefresh} refresh={loadSecuritySettings} />
            <div class="settings-scroll">
              <div class="project-toolbar">
                <button class="btn preset-filled-primary-500" type="button" disabled={settingsLoading || settingsSaving} onclick={saveAuthorizationSettings}>
                  <Save size={16} aria-hidden="true" />{settingsSaving ? 'Сохраняем…' : 'Сохранить'}
                </button>
              </div>
              <section class="settings-card card preset-filled-surface-100-900" aria-label="Настройки авторизации">
                <label class="label session-duration-field">
                  <span class="label-text setting-label">
                    Максимальная длительность сессии
                    <Tooltip positioning={{ placement: 'right' }}>
                      <Tooltip.Trigger class="security-help" aria-label="О максимальной длительности сессии"><CircleHelp size={18} aria-hidden="true" /></Tooltip.Trigger>
                      <Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">Максимальное время бесшовного продления сессии с момента входа. По истечении этого интервала текущая сессия будет завершена, и потребуется снова ввести логин и пароль. Изменение применяется также к уже активным сессиям.</Tooltip.Content></Tooltip.Positioner>
                    </Tooltip>
                  </span>
                  <span class="session-duration-input"><input class="input" type="number" min="1" max="8760" step="1" bind:value={maximumSessionHours} disabled={settingsLoading || settingsSaving} required /><span>часов</span></span>
                </label>
              </section>
              <section class="settings-card card preset-filled-surface-100-900" aria-label="HTTP-авторизация">
                <h3 class="settings-card-title">HTTP-авторизация
                  <Tooltip positioning={{ placement: 'right' }}>
                    <Tooltip.Trigger class="security-help" aria-label="О HTTP-авторизации"><CircleHelp size={18} aria-hidden="true" /></Tooltip.Trigger>
                    <Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">Дополнительная HTTP Basic Auth-защита для проектных хостов OpenResty и панели. Включается только если заполнены оба поля; после сохранения настройки применяются через очередь.</Tooltip.Content></Tooltip.Positioner>
                  </Tooltip>
                </h3>
                <div class="settings-grid two-columns">
                  <label class="label"><span class="label-text">Логин</span><input class="input" type="text" bind:value={httpAuthLogin} disabled={settingsLoading || settingsSaving} autocomplete="off" /></label>
                  <label class="label"><span class="label-text">Пароль</span><input class="input" type="password" bind:value={httpAuthPassword} disabled={settingsLoading || settingsSaving} autocomplete="new-password" /></label>
                </div>
              </section>
            </div>
            {/if}
          </section>
        {/if}
        {#if projectsError}<p class="projects-error" role="status">{projectsError}</p>{/if}
      </section>
    {/if}
  </main>
</div>

{#if userContextMenu}
  <div class="user-context-menu project-context-menu card preset-filled-surface-100-900 shadow-xl" style={`left:${userContextMenu.x}px;top:${userContextMenu.y}px`} role="menu">
    <button type="button" role="menuitem" onclick={() => { userDialog = { create: false, ...userContextMenu.user }; userContextMenu = null; }}><Pencil size={16} aria-hidden="true" />Изменить</button>
    <button class="danger" type="button" role="menuitem" onclick={() => { userDeleteConfirmation = userContextMenu.user; userContextMenu = null; }}><Trash2 size={16} aria-hidden="true" />Удалить</button>
  </div>
{/if}

{#if backupContextMenu}
  <div class="backup-context-menu project-context-menu card preset-filled-surface-100-900 shadow-xl" style={`left:${backupContextMenu.x}px;top:${backupContextMenu.y}px`} role="menu" aria-label={`Действия с бэкапом ${backupContextMenu.backup.name}`}>
    <button type="button" role="menuitem" onclick={() => openBackupCommentDialog(backupContextMenu.backup)}><Pencil size={16} aria-hidden="true" />Изменить</button>
    <button type="button" role="menuitem" onclick={() => openBackupRestoreDialog(backupContextMenu.backup)}><Undo2 size={16} aria-hidden="true" />Восстановить</button>
    <button class="danger" type="button" role="menuitem" onclick={() => openBackupDeleteDialog(backupContextMenu.backup)}><Trash2 size={16} aria-hidden="true" />Удалить</button>
  </div>
{/if}

{#if hookContextMenu}
  <div class="hook-context-menu project-context-menu card preset-filled-surface-100-900 shadow-xl" style={`left:${hookContextMenu.x}px;top:${hookContextMenu.y}px`} role="menu" aria-label={`Действия с хуком ${hookContextMenu.hook.hook}`}>
    <button type="button" role="menuitem" onclick={() => editHookItem(hookContextMenu.hook)}><Pencil size={16} aria-hidden="true" />Изменить</button>
    <button type="button" role="menuitem" onclick={() => { hookToggleConfirmation = hookContextMenu.hook; hookContextMenu = null; }}><Power size={16} aria-hidden="true" />{hookContextMenu.hook.enabled ? 'Выключить' : 'Включить'}</button>
    <button class="danger" type="button" role="menuitem" onclick={() => { hookDeleteConfirmation = hookContextMenu.hook; hookContextMenu = null; }}><Trash2 size={16} aria-hidden="true" />Удалить</button>
  </div>
{/if}

{#if scheduleContextMenu}
  <div class="schedule-context-menu project-context-menu card preset-filled-surface-100-900 shadow-xl" style={`left:${scheduleContextMenu.x}px;top:${scheduleContextMenu.y}px`} role="menu" aria-label={`Действия с командой ${scheduleContextMenu.item.command}`}>
    <button type="button" role="menuitem" onclick={() => editScheduleItem(scheduleContextMenu.item)}><Pencil size={16} aria-hidden="true" />Изменить</button>
    <button type="button" role="menuitem" onclick={() => { scheduleToggleConfirmation = scheduleContextMenu.item; scheduleContextMenu = null; }}><Power size={16} aria-hidden="true" />{scheduleContextMenu.item.enabled ? 'Выключить' : 'Включить'}</button>
    <button class="danger" type="button" role="menuitem" onclick={() => { scheduleDeleteConfirmation = scheduleContextMenu.item; scheduleContextMenu = null; }}><Trash2 size={16} aria-hidden="true" />Удалить</button>
  </div>
{/if}

<Dialog open={Boolean(hookCreateDialog)} onOpenChange={({ open }) => { if (!open && !hookCreating) hookCreateDialog = null; }}>
  <Dialog.Backdrop class="fixed inset-0 z-40 bg-black/50" />
  <Dialog.Positioner class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <Dialog.Content class="login-error-dialog hook-create-dialog card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">Добавить хук</Dialog.Title>
      {#if hookCreateDialog}
        <div class="hook-create-grid">
          <label class="label"><span>Название</span><input class="input" type="text" bind:value={hookCreateDialog.name} placeholder="10-project-up.sh" /></label>
          <label class="scheduler-enabled-option"><input class="checkbox" type="checkbox" bind:checked={hookCreateDialog.enabled} />Включен</label>
          <label class="label"><span>Уровень</span><Combobox collection={hookCreateLevelCollection} value={[hookCreateDialog.level]} openOnClick onValueChange={(details) => { if (details.value[0] && hookCreateDialog) hookCreateDialog.level = details.value[0]; }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each hookCreateLevelOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
          <label class="label"><span>Команда</span><Combobox collection={hookCommandCollection} value={[hookCreateDialog.command]} openOnClick onValueChange={(details) => { if (details.value[0] && hookCreateDialog) hookCreateDialog.command = details.value[0]; }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each hookCommands as value}<Combobox.Item item={{ value, label: value }} class="font-combobox-item"><Combobox.ItemText>{value}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
          <label class="label"><span>Время выполнения</span><Combobox collection={hookCreateTimingCollection} value={[hookCreateDialog.timing]} openOnClick onValueChange={(details) => { if (details.value[0] && hookCreateDialog) hookCreateDialog.timing = details.value[0]; }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each hookCreateTimingOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
        </div>
      {/if}
      <div class="login-error-actions system-confirm-actions"><button class="btn preset-tonal" type="button" disabled={hookCreating} onclick={() => hookCreateDialog = null}>Отмена</button><button class="btn preset-filled-primary-500" type="button" disabled={hookCreating || !hookCreateDialog?.name.trim() || !hookCreateDialog?.command} onclick={createHookItem}><Save size={16} aria-hidden="true" />{hookCreating ? 'Сохраняем…' : 'Сохранить'}</button></div>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(hookEditorDialog)} onOpenChange={({ open }) => { if (!open && !hookEditorSaving) hookEditorDialog = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog hook-editor-dialog card preset-filled-surface-100-900 shadow-2xl">
      <div class="hook-editor-header">
        <Dialog.Title class="login-error-title">Изменить хук {hookEditorDialog?.hook.hook}</Dialog.Title>
        <div class="hook-editor-theme-picker">
          <button class="btn btn-sm preset-tonal theme-trigger" type="button" aria-label="Цветовая схема редактора" aria-haspopup="dialog" aria-expanded={editorThemeOpen} onclick={() => { editorThemeOpen = !editorThemeOpen; }}><span class="editor-theme-dot" aria-hidden="true"></span>{editorThemes.find(([value]) => value === editorTheme)?.[1]}</button>
          {#if editorThemeOpen}
            <div class="editor-theme-menu theme-menu card preset-filled-surface-100-900 shadow-2xl" role="dialog" aria-label="Цветовая схема редактора">
              <div class="theme-grid" role="list" aria-label="Цветовая схема CodeMirror">
                {#each editorThemes as [value, label, , colors]}
                  <button class:active={editorTheme === value} class="theme-option editor-theme-option" type="button" aria-label={label} aria-pressed={editorTheme === value} title={label} onclick={() => setEditorTheme(value)}><span class="editor-theme-swatch" style={`--editor-bg:${colors.background};--editor-fg:${colors.foreground};--editor-gutter:${colors.gutter}`}></span>{label}</button>
                {/each}
              </div>
            </div>
          {/if}
        </div>
      </div>
      {#if hookEditorLoading}
        <div class="hook-editor-loading animate-pulse">Загрузка…</div>
      {:else if hookEditorDialog}
        <label class="scheduler-enabled-option hook-editor-enabled"><input class="checkbox" type="checkbox" bind:checked={hookEditorDialog.enabled} />Включен</label>
        <div class="hook-editor-fields">
          <label class="label"><span class="label-text">Название файла</span><input class="input" type="text" bind:value={hookEditorDialog.name} /></label>
          <label class="label"><span class="label-text">Команда</span><Combobox collection={hookCommandCollection} value={[hookEditorDialog.command]} openOnClick onValueChange={(details) => { if (details.value[0]) hookEditorDialog.command = details.value[0]; }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each hookCommands as value}<Combobox.Item item={{ value, label: value }} class="font-combobox-item"><Combobox.ItemText>{value}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
          <label class="label"><span class="label-text">Время выполнения</span><Combobox collection={hookCreateTimingCollection} value={[hookEditorDialog.timing]} openOnClick onValueChange={(details) => { if (details.value[0]) hookEditorDialog.timing = details.value[0]; }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each hookCreateTimingOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
        </div>
        <div class:hook-editor-compact={Boolean(hookEditorDialog?.runResult)} class="hook-code-editor" use:mountHookEditor aria-label="Редактор кода хука"></div>
        {#if hookEditorDialog?.runResult}
          <div class="hook-run-result hook-code-editor" use:mountHookRunResultEditor aria-label="Результат выполнения хука"></div>
        {/if}
        <div class="hook-run-grid">
          <input class="input hook-run-input" type="text" aria-label="Профиль команды хука" value={hookEditorDialog?.profile || ''} oninput={(event) => { if (hookEditorDialog) hookEditorDialog = { ...hookEditorDialog, profile: event.currentTarget.value }; }} />
          <div class="hook-run-row"><Combobox collection={hookProjectCollection} value={[hookEditorDialog?.project || '']} openOnClick onValueChange={(details) => setHookWorkingDirectory(details.value[0] || '')}><Combobox.Control class="font-combobox-control hook-project-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each [{ value: '', label: 'Проект не выбран' }, ...projects.map((project) => ({ value: project.name, label: project.name }))] as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox><input class="input hook-run-input" type="text" aria-label="Рабочая директория хука" value={hookEditorDialog?.workingDirectory || ''} oninput={(event) => { if (hookEditorDialog) hookEditorDialog = { ...hookEditorDialog, workingDirectory: event.currentTarget.value, project: '' }; }} /><button class="btn preset-filled-primary-500 hook-run-button" type="button" disabled={hookRunning || hookEditorSaving} onclick={runHookEditor}><Play size={16} aria-hidden="true" />{hookRunning ? 'Выполняем…' : 'Выполнить'}</button></div>
        </div>
      {/if}
      <div class="login-error-actions">
        <button class="btn preset-tonal" type="button" disabled={hookEditorSaving || hookRunning} onclick={() => { hookEditorDialog = null; }}>Отмена</button>
        <button class="btn preset-filled-primary-500" type="button" disabled={hookEditorLoading || hookEditorSaving || hookRunning || !hookEditorDialog?.name.trim() || !hookEditorDialog?.command || !hookEditorDialog?.timing} onclick={saveHookEditor}><Save size={16} aria-hidden="true" />{hookEditorSaving ? 'Сохраняем…' : 'Сохранить'}</button>
      </div>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(userDialog)} onOpenChange={({ open }) => { if (!open) userDialog = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog user-dialog card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">{userDialog?.create ? 'Добавить пользователя' : 'Изменить пользователя'}</Dialog.Title>
      <div class="user-dialog-fields">
        <label class="label"><span class="label-text">Email</span><input class="input" type="email" value={userDialog?.login || ''} disabled={!userDialog?.create} oninput={(event) => { if (userDialog) userDialog = { ...userDialog, login: event.currentTarget.value }; }} required /></label>
        <label class="label"><span class="label-text">Комментарии</span><textarea class="textarea" rows="5" value={userDialog?.comments || ''} oninput={(event) => { if (userDialog) userDialog = { ...userDialog, comments: event.currentTarget.value }; }}></textarea></label>
      </div>
      {#if !userDialog?.create}<button class="btn preset-tonal user-password-button" type="button" onclick={() => rotateUserPassword(userDialog)}>Изменить пароль</button>{/if}
      <div class="login-error-actions"><Dialog.CloseTrigger class="btn preset-tonal" type="button">Отмена</Dialog.CloseTrigger><button class="btn preset-filled-primary-500" type="button" disabled={!userDialog?.login.trim()} onclick={saveUser}>Сохранить</button></div>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(userDeleteConfirmation)} onOpenChange={({ open }) => { if (!open) userDeleteConfirmation = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">Удалить пользователя?</Dialog.Title>
      <Dialog.Description class="login-error-description">Пользователь {userDeleteConfirmation?.login} будет удалён, а все его сессии завершены.</Dialog.Description>
      {#if userDeleteConfirmation?.login === currentLogin}<p class="user-self-warning">Вы удаляете текущего пользователя. После удаления потребуется авторизоваться под другой учётной записью.</p>{/if}
      <div class="login-error-actions"><Dialog.CloseTrigger class="btn preset-tonal" type="button">Отмена</Dialog.CloseTrigger><button class="btn preset-filled-error-500" type="button" onclick={confirmDeleteUser}>Удалить</button></div>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(userPasswordAlert)} closeOnEscape={false} closeOnInteractOutside={false}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">{userPasswordAlert?.created ? `Пользователь ${userPasswordAlert?.login} добавлен` : `Пароль пользователя ${userPasswordAlert?.login} изменён`}</Dialog.Title>
      <Dialog.Description class="login-error-description">Скопируйте и сохраните пароль — он показывается только один раз.</Dialog.Description>
      <div class="generated-password"><code>{userPasswordAlert?.password}</code><button class="btn-icon preset-tonal" type="button" aria-label="Скопировать пароль" title={userPasswordAlert?.copied ? 'Скопировано' : 'Скопировать'} onclick={copyGeneratedPassword}><Copy size={16} aria-hidden="true" /></button></div>
      <div class="login-error-actions"><button class="btn preset-filled-primary-500" type="button" onclick={() => { const shouldLogout = userPasswordAlert?.logout; userPasswordAlert = null; if (shouldLogout) logout(); }}>Закрыть</button></div>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

{#if projectContextMenu}
  <div
    class="project-context-menu card preset-filled-surface-100-900 shadow-2xl"
    role="menu"
    aria-label={`Действия с проектом ${projectContextMenu.project.name}`}
    style={`left: ${projectContextMenu.x}px; top: ${projectContextMenu.y}px;`}
  >
    {#if projectContextMenu.project.url}
      <a href={projectContextMenu.project.url} target="_blank" rel="noreferrer" role="menuitem" onclick={() => { projectContextMenu = null; }}>
        <ExternalLink size={16} aria-hidden="true" />Открыть
      </a>
    {:else}
      <button type="button" role="menuitem" disabled><ExternalLink size={16} aria-hidden="true" />Открыть</button>
    {/if}
    <hr />
    <button type="button" role="menuitem" onclick={() => openProjectUpdateDialog(projectContextMenu.project)}>
      <Pencil size={16} aria-hidden="true" />Изменить
    </button>
    <button type="button" role="menuitem" onclick={() => openProjectCloneDialog(projectContextMenu.project)}>
      <Copy size={16} aria-hidden="true" />Клонировать
    </button>
    <button type="button" role="menuitem" onclick={() => runContextProjectAction(projectContextMenu.project.enabled ? 'disable' : 'enable')}>
      <Power size={16} aria-hidden="true" />{projectContextMenu.project.enabled ? 'Отключить' : 'Включить'}
    </button>
    <button class="danger" type="button" role="menuitem" onclick={() => runContextProjectAction('wipe')}>
      <Trash2 size={16} aria-hidden="true" />Стереть
    </button>
    <button class="danger" type="button" role="menuitem" onclick={() => runContextProjectAction('delete')}>
      <Trash2 size={16} aria-hidden="true" />Удалить
    </button>
  </div>
{/if}

<Dialog open={Boolean(projectCloneDialog)} onOpenChange={({ open }) => { if (!open && !projectCloning) projectCloneDialog = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog project-clone-dialog card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">Клонировать проект</Dialog.Title>
      {#if projectCloneDialog}
        <form class="project-add-form project-clone-form" onsubmit={(event) => { event.preventDefault(); submitProjectClone(); }}>
          <label class="label"><span class="label-text">Имя проекта (опционально)</span><input class="input" bind:value={projectCloneDialog.to} pattern="[a-z0-9](?:[a-z0-9-]*[a-z0-9])?" /></label>
          <label class="label"><span class="label-text project-clone-help-heading">Расположение (опционально)<Tooltip positioning={{ placement: 'right' }}><Tooltip.Trigger class="security-help" type="button" aria-label="О расположении клона"><CircleHelp size={16} aria-hidden="true" /></Tooltip.Trigger><Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">Определяет, куда будут скопированы файлы проекта. Если выбрать «Рядом с исходным проектом», новый каталог будет создан в той же родительской директории.</Tooltip.Content></Tooltip.Positioner></Tooltip></span><Combobox collection={projectCloneLocationCollection} value={[projectCloneDialog.location]} openOnClick onValueChange={(details) => { projectCloneDialog.location = details.value[0] ?? ''; }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each [{ value: '', label: 'Рядом с исходным проектом' }, ...projectAddOptions.locations.map((location) => ({ value: location.code, label: location.code }))] as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
          <fieldset class="project-clone-dbms"><legend class="label-text"><span class="project-clone-help-heading">Выбрать БД для клонирования<Tooltip positioning={{ placement: 'right' }}><Tooltip.Trigger class="security-help" type="button" aria-label="О клонировании баз данных"><CircleHelp size={16} aria-hidden="true" /></Tooltip.Trigger><Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">Отмеченные базы будут скопированы вместе с данными. Для неотмеченных СУБД база клона будет создана пустой.</Tooltip.Content></Tooltip.Positioner></Tooltip></span></legend><div class="project-deployment-checkboxes"><label class="project-deployment-checkbox"><input class="checkbox" type="checkbox" bind:checked={projectCloneDialog.mysql} /><span>MySQL</span></label><label class="project-deployment-checkbox"><input class="checkbox" type="checkbox" bind:checked={projectCloneDialog.postgres} /><span>PostgreSQL</span></label></div></fieldset>
          <fieldset class="project-clone-dbms project-dedicated-db"><legend class="label-text"><span class="project-clone-help-heading">Инстансы баз данных клона<Tooltip positioning={{ placement: 'right' }}><Tooltip.Trigger class="security-help" type="button" aria-label="Об инстансах баз данных клона"><CircleHelp size={16} aria-hidden="true" /></Tooltip.Trigger><Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">Для отмеченных СУБД будут созданы отдельные попроектные инстансы. Если выключить опцию, все базы клона будут размещены в общих системных инстансах независимо от настроек исходного проекта.</Tooltip.Content></Tooltip.Positioner></Tooltip></span></legend>
            <label class="project-deployment-checkbox project-dedicated-toggle"><input class="checkbox" type="checkbox" bind:checked={projectCloneDialog.dedicated} /><span>Выделенные инстансы БД</span></label>
            {#if projectCloneDialog.dedicated}
              <div class="project-deployment-checkboxes">
                <label class="project-deployment-checkbox"><input class="checkbox" type="checkbox" bind:checked={projectCloneDialog.dedicatedMysql} /><span>MySQL</span></label>
                <label class="project-deployment-checkbox"><input class="checkbox" type="checkbox" bind:checked={projectCloneDialog.dedicatedPostgres} /><span>PostgreSQL</span></label>
              </div>
              {#if projectCloneDialog.dedicatedMysql}<label class="label project-dedicated-location"><span class="label-text project-clone-help-heading">Расположение MySQL<Tooltip positioning={{ placement: 'right' }}><Tooltip.Trigger class="security-help" type="button" aria-label="О расположении MySQL"><CircleHelp size={16} aria-hidden="true" /></Tooltip.Trigger><Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">Выбирает каталог для файлов данных и логов выделенного MySQL. «Системное расположение» хранит их внутри каталога данных docker-cli.</Tooltip.Content></Tooltip.Positioner></Tooltip></span><Combobox collection={projectDatabaseLocationCollection} value={[projectCloneDialog.locationMysql]} openOnClick onValueChange={(details) => { projectCloneDialog.locationMysql = details.value[0] ?? 'system'; }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each projectDatabaseLocationOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>{/if}
              {#if projectCloneDialog.dedicatedPostgres}<label class="label project-dedicated-location"><span class="label-text project-clone-help-heading">Расположение PostgreSQL<Tooltip positioning={{ placement: 'right' }}><Tooltip.Trigger class="security-help" type="button" aria-label="О расположении PostgreSQL"><CircleHelp size={16} aria-hidden="true" /></Tooltip.Trigger><Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">Выбирает каталог для файлов данных и логов выделенного PostgreSQL. «Системное расположение» хранит их внутри каталога данных docker-cli.</Tooltip.Content></Tooltip.Positioner></Tooltip></span><Combobox collection={projectDatabaseLocationCollection} value={[projectCloneDialog.locationPostgres]} onValueChange={(details) => { projectCloneDialog.locationPostgres = details.value[0] ?? 'system'; }} openOnClick><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each projectDatabaseLocationOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>{/if}
            {/if}
          </fieldset>
          <div class="login-error-actions"><button class="btn preset-tonal" type="button" disabled={projectCloning} onclick={() => { projectCloneDialog = null; }}>Отмена</button><button class="btn preset-filled-primary-500" type="submit" disabled={projectCloning || (projectCloneDialog.dedicated && !projectCloneDialog.dedicatedMysql && !projectCloneDialog.dedicatedPostgres)}>{projectCloning ? 'Добавляем…' : 'Добавить'}</button></div>
        </form>
      {/if}
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(projectUpdateDialog)} onOpenChange={({ open }) => { if (!open && !projectUpdating) projectUpdateDialog = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">Изменить проект</Dialog.Title>
      {#if projectUpdateDialog}
        <form class="project-add-form" onsubmit={(event) => { event.preventDefault(); submitProjectUpdate(); }}>
          <label class="label"><span class="label-text">Имя</span><input class="input" bind:value={projectUpdateDialog.name} required pattern="[a-z0-9](?:[a-z0-9-]*[a-z0-9])?" /></label>
          <label class="label"><span class="label-text">Язык</span><Combobox collection={projectLanguageCollection} value={[projectUpdateDialog.language]} openOnClick onValueChange={(details) => { if (details.value[0]) { projectUpdateDialog.language = details.value[0]; projectUpdateDialog.framework = projectAddOptions.frameworks[projectUpdateDialog.language]?.[0]?.code || ''; } }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each projectAddOptions.languages.map((language) => ({ value: language.code, label: language.name })) as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
          {#if projectUpdateDialog.language === 'php'}
            <label class="label"><span class="label-text">Версия PHP</span><Combobox collection={projectLanguageVersionCollection} value={[projectUpdateDialog.languageVersion]} openOnClick onValueChange={(details) => { if (details.value[0]) projectUpdateDialog.languageVersion = details.value[0]; }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each projectAddOptions.languageVersions.map((version) => ({ value: version, label: version })) as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
          {/if}
          <label class="label"><span class="label-text">Фреймворк</span><Combobox collection={projectUpdateFrameworkCollection} value={[projectUpdateDialog.framework]} openOnClick onValueChange={(details) => { projectUpdateDialog.framework = details.value[0] ?? ''; }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each (projectAddOptions.frameworks[projectUpdateDialog.language] || []).map((framework) => ({ value: framework.code, label: framework.name })) as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
          <div class="login-error-actions"><button class="btn preset-tonal" type="button" disabled={projectUpdating} onclick={() => { projectUpdateDialog = null; }}>Отмена</button><button class="btn preset-filled-primary-500" type="submit" disabled={projectUpdating || !projectUpdateDialog.name}>{projectUpdating ? 'Изменяем…' : 'Изменить'}</button></div>
        </form>
      {/if}
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(projectAddDialog)} onOpenChange={({ open }) => { if (!open && !projectAdding) projectAddDialog = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog project-add-dialog card preset-filled-surface-100-900 shadow-2xl">
      {#if projectAddDialog}
      <Dialog.Title class="login-error-title">Добавить проект</Dialog.Title>
      <form class="project-add-form" onsubmit={(event) => { event.preventDefault(); addProject(); }}>
        <div class="project-add-main">
          <label class="label"><span class="label-text">Код (опционально)</span><input class="input" bind:value={projectAddDialog.code} pattern="[a-z0-9](?:[a-z0-9-]*[a-z0-9])?" /></label>
          <label class="label"><span class="label-text project-clone-help-heading">Расположение файлов<Tooltip positioning={{ placement: 'right' }}><Tooltip.Trigger class="security-help" type="button" aria-label="О расположении файлов проекта"><CircleHelp size={16} aria-hidden="true" /></Tooltip.Trigger><Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">Определяет родительский каталог, в котором будет создан новый проект.</Tooltip.Content></Tooltip.Positioner></Tooltip></span><Combobox collection={projectLocationCollection} value={[projectAddDialog.location]} openOnClick onValueChange={(details) => { if (details.value[0]) projectAddDialog.location = details.value[0]; }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly required /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each projectLocationOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
          <label class="label"><span class="label-text">Язык</span><Combobox collection={projectLanguageCollection} value={[projectAddDialog.language]} openOnClick onValueChange={(details) => { if (details.value[0]) { projectAddDialog.language = details.value[0]; projectAddDialog.framework = projectAddOptions.frameworks[projectAddDialog.language]?.[0]?.code || ''; } }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each projectAddOptions.languages.map((language) => ({ value: language.code, label: language.name })) as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
          <label class="label"><span class="label-text">Фреймворк</span><Combobox collection={projectFrameworkCollection} value={[projectAddDialog.framework]} openOnClick onValueChange={(details) => { projectAddDialog.framework = details.value[0] ?? ''; }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each (projectAddOptions.frameworks[projectAddDialog.language] || []).map((framework) => ({ value: framework.code, label: framework.name })) as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
          <fieldset class="project-clone-dbms project-dedicated-db"><legend class="label-text"><span class="project-clone-help-heading">Базы данных<Tooltip positioning={{ placement: 'right' }}><Tooltip.Trigger class="security-help" type="button" aria-label="Об инстансах баз данных проекта"><CircleHelp size={16} aria-hidden="true" /></Tooltip.Trigger><Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">Для отмеченных СУБД будут созданы отдельные попроектные инстансы. Если опцию не включать, проект будет использовать общие системные инстансы.</Tooltip.Content></Tooltip.Positioner></Tooltip></span></legend>
            <label class="project-deployment-checkbox project-dedicated-toggle"><input class="checkbox" type="checkbox" bind:checked={projectAddDialog.dedicated} /><span>Выделенные инстансы БД</span></label>
            {#if projectAddDialog.dedicated}
              <div class="project-deployment-checkboxes">
                <label class="project-deployment-checkbox"><input class="checkbox" type="checkbox" bind:checked={projectAddDialog.mysql} /><span>MySQL</span></label>
                <label class="project-deployment-checkbox"><input class="checkbox" type="checkbox" bind:checked={projectAddDialog.postgres} /><span>PostgreSQL</span></label>
              </div>
              {#if projectAddDialog.mysql}<label class="label project-dedicated-location"><span class="label-text project-clone-help-heading">Расположение MySQL<Tooltip positioning={{ placement: 'right' }}><Tooltip.Trigger class="security-help" type="button" aria-label="О расположении MySQL"><CircleHelp size={16} aria-hidden="true" /></Tooltip.Trigger><Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">Выбирает каталог для файлов данных и логов выделенного MySQL. «Системное расположение» хранит их внутри каталога данных docker-cli.</Tooltip.Content></Tooltip.Positioner></Tooltip></span><Combobox collection={projectDatabaseLocationCollection} value={[projectAddDialog.locationMysql]} openOnClick onValueChange={(details) => { projectAddDialog.locationMysql = details.value[0] ?? 'system'; }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each projectDatabaseLocationOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>{/if}
              {#if projectAddDialog.postgres}<label class="label project-dedicated-location"><span class="label-text project-clone-help-heading">Расположение PostgreSQL<Tooltip positioning={{ placement: 'right' }}><Tooltip.Trigger class="security-help" type="button" aria-label="О расположении PostgreSQL"><CircleHelp size={16} aria-hidden="true" /></Tooltip.Trigger><Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">Выбирает каталог для файлов данных и логов выделенного PostgreSQL. «Системное расположение» хранит их внутри каталога данных docker-cli.</Tooltip.Content></Tooltip.Positioner></Tooltip></span><Combobox collection={projectDatabaseLocationCollection} value={[projectAddDialog.locationPostgres]} openOnClick onValueChange={(details) => { projectAddDialog.locationPostgres = details.value[0] ?? 'system'; }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each projectDatabaseLocationOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>{/if}
            {/if}
          </fieldset>
        </div>
        <div class="project-add-deployment">
        <label class="label"><span class="label-text">Скрипт развертки</span><Combobox collection={projectDeploymentCollection} value={[projectAddDialog.deploymentScript]} openOnClick onValueChange={(details) => { selectDeploymentScript(details.value[0] ?? ''); }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each [{ value: '', label: 'Не использовать' }, ...projectAddOptions.deploymentScripts.map((script) => ({ value: script.code, label: script.name }))] as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
        {#if selectedDeploymentScript}
          {#if Object.keys(selectedDeploymentScript.parameters).length === 0}
            <p class="project-deployment-hint">Скрипт не требует аргументов.</p>
          {:else}
            <div class="project-deployment-arguments">
              {#each Object.entries(selectedDeploymentScript.parameters) as [name, parameter], parameterIndex}
                {#if parameter.type === 'boolean'}
                  <div class="project-deployment-boolean">
                    <label class="project-deployment-checkbox" for={`deployment-parameter-${parameterIndex}`}><input id={`deployment-parameter-${parameterIndex}`} class="checkbox" type="checkbox" checked={projectAddDialog.deploymentArguments[name] === true} onchange={(event) => setDeploymentArgument(name, event.currentTarget.checked)} /><span>{parameter.name || name}</span></label>
                    {#if parameter.description}<Tooltip><Tooltip.Trigger class="security-help project-parameter-help" type="button" aria-label={`Описание параметра ${parameter.name || name}`}><CircleHelp size={15} aria-hidden="true" /></Tooltip.Trigger><Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">{parameter.description}</Tooltip.Content></Tooltip.Positioner></Tooltip>{/if}
                  </div>
                {:else if parameter.type === 'list'}
                  <div class="label project-parameter-field"><span class="project-parameter-heading"><label class="label-text" for={`deployment-parameter-${parameterIndex}`}>{parameter.name || name}{parameter.required ? ' *' : ''}</label>{#if parameter.description}<Tooltip><Tooltip.Trigger class="security-help project-parameter-help" type="button" aria-label={`Описание параметра ${parameter.name || name}`}><CircleHelp size={15} aria-hidden="true" /></Tooltip.Trigger><Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">{parameter.description}</Tooltip.Content></Tooltip.Positioner></Tooltip>{/if}</span><select id={`deployment-parameter-${parameterIndex}`} class="select" required={parameter.required} value={projectAddDialog.deploymentArguments[name]} onchange={(event) => setDeploymentArgument(name, event.currentTarget.value)}><option value="">Не выбрано</option>{#each parameter.items || [] as item}<option value={item.value}>{item.name || item.value}</option>{/each}</select></div>
                {:else}
                  <div class="label project-parameter-field"><span class="project-parameter-heading"><label class="label-text" for={`deployment-parameter-${parameterIndex}`}>{parameter.name || name}{parameter.required ? ' *' : ''}</label>{#if parameter.description}<Tooltip><Tooltip.Trigger class="security-help project-parameter-help" type="button" aria-label={`Описание параметра ${parameter.name || name}`}><CircleHelp size={15} aria-hidden="true" /></Tooltip.Trigger><Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">{parameter.description}</Tooltip.Content></Tooltip.Positioner></Tooltip>{/if}</span><input id={`deployment-parameter-${parameterIndex}`} class="input" type={parameter.type === 'integer' ? 'number' : 'text'} required={parameter.required} min={parameter.min} max={parameter.max} value={projectAddDialog.deploymentArguments[name]} oninput={(event) => setDeploymentArgument(name, parameter.type === 'integer' && event.currentTarget.value !== '' ? event.currentTarget.valueAsNumber : event.currentTarget.value)} /></div>
                {/if}
              {/each}
            </div>
          {/if}
        {/if}
        </div>
        <div class="login-error-actions"><button class="btn preset-tonal" type="button" disabled={projectAdding} onclick={() => { projectAddDialog = null; }}>Отмена</button><button class="btn preset-filled-primary-500" type="submit" disabled={projectAdding || !projectAddDialog.location || (projectAddDialog.dedicated && !projectAddDialog.mysql && !projectAddDialog.postgres)}>{projectAdding ? 'Добавляем…' : 'Добавить'}</button></div>
      </form>
      {/if}
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(projectConfirmation)} onOpenChange={({ open }) => { if (!open) projectConfirmation = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class={`login-error-dialog card preset-filled-surface-100-900 shadow-2xl${projectConfirmation?.action === 'wipe' || projectConfirmation?.action === 'delete' ? ' error-alert' : ''}`}>
      <Dialog.Title class="login-error-title">{projectConfirmation?.action === 'delete' ? 'Удалить проект?' : projectConfirmation?.action === 'wipe' ? 'Стереть проект?' : 'Отключить проект?'}</Dialog.Title>
      <Dialog.Description class="login-error-description">
        {#if projectConfirmation?.action === 'wipe'}
          Все файлы из директории проекта «{projectConfirmation?.project.name}», кроме служебной директории .docker-cli, будут безвозвратно удалены.
        {:else if projectConfirmation?.action === 'delete'}
          Проект «{projectConfirmation?.project.name}» будет полностью удалён вместе с директорией, служебными файлами, базами данных и пользователями СУБД. Это действие необратимо.
        {:else}
          Проект «{projectConfirmation?.project.name}» станет недоступен через веб-сервер. Его можно будет включить снова.
        {/if}
      </Dialog.Description>
      <div class="login-error-actions system-confirm-actions">
        <Dialog.CloseTrigger class="btn preset-tonal" type="button">Отмена</Dialog.CloseTrigger>
        <button class={`btn ${projectConfirmation?.action === 'wipe' || projectConfirmation?.action === 'delete' ? 'preset-filled-error-500' : 'preset-filled-primary-500'}`} type="button" onclick={() => { const confirmation = projectConfirmation; projectConfirmation = null; if (confirmation) projectAction(confirmation.action, confirmation.project.name); }}>
          {projectConfirmation?.action === 'delete' ? 'Удалить' : projectConfirmation?.action === 'wipe' ? 'Стереть' : 'Отключить'}
        </button>
      </div>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(protectedAlert)} role="alertdialog" onOpenChange={({ open }) => { if (!open) protectedAlert = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog error-alert card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">Проект защищен</Dialog.Title>
      <Dialog.Description class="login-error-description">Проект «{protectedAlert?.name}» защищен. Изменение файлов и баз данных запрещено.</Dialog.Description>
      <div class="login-error-actions"><Dialog.CloseTrigger class="btn preset-filled-primary-500" type="button">Закрыть</Dialog.CloseTrigger></div>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(queuedOperationNotice)} onOpenChange={({ open }) => { if (!open) queuedOperationNotice = ''; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog info-dialog card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">Операция поставлена в очередь</Dialog.Title>
      <Dialog.Description class="login-error-description">{queuedOperationNotice}</Dialog.Description>
      <div class="login-error-actions">
        <Dialog.CloseTrigger class="btn preset-filled-primary-500" type="button">ОК</Dialog.CloseTrigger>
      </div>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(fileStrategyDialog)} onOpenChange={({ open }) => { if (!open) fileStrategyDialog = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog file-strategy-dialog card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">Настройки стратегии</Dialog.Title>
      {#if fileStrategyDialog}
        <Tabs class="strategy-tabs" value={fileStrategyDialog.tab} onValueChange={(details) => { fileStrategyDialog = { ...fileStrategyDialog, tab: details.value }; }}>
          <Tabs.List class="strategy-tabs-list"><Tabs.Trigger class="strategy-tab" value="files">Файлы</Tabs.Trigger><Tabs.Trigger class="strategy-tab" value="database">БД</Tabs.Trigger></Tabs.List>
          {#each strategyTabs as strategyTab}
            <Tabs.Content class="file-strategy-fields" value={strategyTab.value}>
              {#each strategyTab.sections as [kind, title, hint]}
                <section class="strategy-pattern-section"><h3>{title}<Tooltip positioning={{ placement: 'right' }}><Tooltip.Trigger class="security-help" aria-label={`О блоке ${title}`}><CircleHelp size={18} aria-hidden="true" /></Tooltip.Trigger><Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">{hint}</Tooltip.Content></Tooltip.Positioner></Tooltip></h3><div class="strategy-pattern-list">{#each fileStrategyDialog[kind] as pattern, index}<div class="strategy-pattern-row"><input class="input" type="text" value={pattern} placeholder={strategyTab.value === 'database' ? 'таблица или glob-маска' : 'путь или glob-маска'} aria-label={`${title}: шаблон ${index + 1}`} oninput={(event) => updateStrategyPattern(kind, index, event.currentTarget.value)} /><button class="btn preset-tonal" type="button" title="Добавить" aria-label={`Добавить паттерн в ${title.toLocaleLowerCase()}`} onclick={() => addStrategyPattern(kind)}><Plus size={16} aria-hidden="true" /></button><button class="btn preset-tonal location-delete" type="button" title="Удалить" aria-label={`Удалить паттерн из ${title.toLocaleLowerCase()}`} onclick={() => removeStrategyPattern(kind, index)}>−</button></div>{/each}</div></section>
              {/each}
            </Tabs.Content>
          {/each}
        </Tabs>
      {/if}
      <div class="login-error-actions">
        <Dialog.CloseTrigger class="btn preset-tonal" type="button">Отменить</Dialog.CloseTrigger>
        <button class="btn preset-filled-primary-500" type="button" onclick={saveFileStrategySettings}>Сохранить</button>
      </div>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(backupRestoreConfirmation)} onOpenChange={({ open }) => { if (!open && !backupRestorePending) backupRestoreConfirmation = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog backup-restore-dialog error-alert card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">Восстановить бэкап?</Dialog.Title>
      {#if backupRestoreConfirmation}
        <div class="backup-restore-content">
          {#if backupRestoreConfirmation.hasDatabase}
            <section class="backup-restore-section">
              <h3>БД</h3>
              <div class="backup-restore-options">
                {#each backupRestoreConfirmation.databaseCodes as database}
                  <label><input class="checkbox" type="checkbox" checked={backupRestoreConfirmation.restoreDatabases.includes(database)} onchange={(event) => toggleRestoreDatabase(database, event.currentTarget.checked)} />{database === 'mysql' ? 'MySQL' : 'PostgreSQL'}</label>
                {/each}
              </div>
              {#if backupRestoreConfirmation.restoreDatabases.length}
                <p class="backup-restore-warning">Выбранные базы проекта «{selectedProjectName}» будут полностью заменены данными из бэкапа «{backupRestoreConfirmation.name}».</p>
              {:else}<p>Базы данных восстанавливаться не будут.</p>{/if}
              <div class="backup-strategy-contents">
                <p>Стратегия: {backupRestoreConfirmation.strategy || 'без стратегии'}. Дамп включает:</p>
                {#if backupRestoreConfirmation.databaseStrategyTables?.include?.length}<ul>{#each backupRestoreConfirmation.databaseStrategyTables.include as pattern}<li><code>{pattern}</code></li>{/each}</ul>{:else}<ul><li>Все таблицы</li></ul>{/if}
                <p>Из включённого исключены:</p>
                {#if backupRestoreConfirmation.databaseStrategyTables?.exclude?.length}<ul>{#each backupRestoreConfirmation.databaseStrategyTables.exclude as pattern}<li><code>{pattern}</code></li>{/each}</ul>{:else}<ul><li>Исключений нет</li></ul>{/if}
              </div>
            </section>
          {/if}
          {#if backupRestoreConfirmation.hasFiles}
            <section class="backup-restore-section">
              <h3>Файлы</h3>
              <div class="backup-restore-options">
                {#if backupRestoreConfirmation.hasDatabase}<label><input class="checkbox" type="checkbox" checked={backupRestoreConfirmation.restoreFiles} disabled={backupRestoreConfirmation.filesValid === false} onchange={(event) => { backupRestoreConfirmation = { ...backupRestoreConfirmation, restoreFiles: event.currentTarget.checked }; }} />Восстановить файлы</label>{/if}
                <label><input class="checkbox" type="checkbox" checked={backupRestoreConfirmation.force} disabled={!backupRestoreConfirmation.restoreFiles} onchange={(event) => { backupRestoreConfirmation = { ...backupRestoreConfirmation, force: event.currentTarget.checked }; }} />Перезаписывать файлы</label>
                <label><input class="checkbox" type="checkbox" checked={backupRestoreConfirmation.wipe} disabled={!backupRestoreConfirmation.restoreFiles} onchange={(event) => { backupRestoreConfirmation = { ...backupRestoreConfirmation, wipe: event.currentTarget.checked }; }} />Предварительно стереть все файлы</label>
              </div>
              {#if backupRestoreConfirmation.filesValid === false}<p class="backup-restore-warning">Файлы восстановить нельзя: {backupRestoreConfirmation.filesError}</p>{/if}
              {#if !backupRestoreConfirmation.restoreFiles}<p>Файлы восстанавливаться не будут.</p>
              {:else}<p class="backup-restore-warning">
                {#if backupRestoreConfirmation.wipe}
                  Все текущие файлы проекта, кроме содержимого .docker-cli, будут удалены перед восстановлением. {backupRestoreConfirmation.force ? 'Файлы бэкапа будут восстановлены с разрешением перезаписи.' : 'После очистки файлы бэкапа будут восстановлены без перезаписи существующих файлов.'}
                {:else if backupRestoreConfirmation.force}
                  Файлы из бэкапа заменят одноимённые файлы проекта. Остальные файлы проекта останутся без изменений.
                {:else}
                  Файлы будут восстановлены без перезаписи. Если одноимённый файл уже существует, восстановление завершится ошибкой; остальные файлы проекта останутся без изменений.
                {/if}
              </p>{/if}
              <div class="backup-strategy-contents">
                <p>Бэкап включает:</p>
                {#if backupRestoreConfirmation.strategyPaths?.include?.length}
                  <ul>{#each backupRestoreConfirmation.strategyPaths.include as pattern}<li><code>{pattern}</code></li>{/each}</ul>
                {:else}<ul><li>Все файлы и каталоги проекта</li></ul>{/if}
                <p>Из включённого исключены:</p>
                {#if backupRestoreConfirmation.strategyPaths?.exclude?.length}
                  <ul>{#each backupRestoreConfirmation.strategyPaths.exclude as pattern}<li><code>{pattern}</code></li>{/each}</ul>
                {:else}<ul><li>Исключений нет</li></ul>{/if}
              </div>
            </section>
          {/if}
        </div>
      {/if}
      <div class="login-error-actions system-confirm-actions">
        <Dialog.CloseTrigger class="btn preset-tonal" type="button">Отмена</Dialog.CloseTrigger>
        <button class="btn preset-filled-error-500" type="button" disabled={backupRestorePending || (!backupRestoreConfirmation?.restoreFiles && !backupRestoreConfirmation?.restoreDatabases?.length)} onclick={restoreBackup}>Восстановить</button>
      </div>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(backupDeleteConfirmation)} onOpenChange={({ open }) => { if (!open && !backupDeletePending) backupDeleteConfirmation = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog error-alert card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">Удалить бэкап?</Dialog.Title>
      {#if backupDeleteConfirmation}
        <div class="backup-delete-content">
          {#if backupDeleteConfirmation.databaseCodes.length + (backupDeleteConfirmation.hasFiles ? 1 : 0) > 1}
            <div class="backup-restore-options">
              {#each backupDeleteConfirmation.databaseCodes as database}
                <label><input class="checkbox" type="checkbox" checked={backupDeleteConfirmation.deleteDatabases.includes(database)} onchange={(event) => toggleDeleteDatabase(database, event.currentTarget.checked)} />Удалить {database === 'mysql' ? 'MySQL' : 'PostgreSQL'}</label>
              {/each}
              {#if backupDeleteConfirmation.hasFiles}<label><input class="checkbox" type="checkbox" checked={backupDeleteConfirmation.deleteFiles} onchange={(event) => { backupDeleteConfirmation = { ...backupDeleteConfirmation, deleteFiles: event.currentTarget.checked }; }} />Удалить файлы</label>{/if}
            </div>
          {/if}
          <p class="login-error-description">Выбранные части бэкапа «{backupDeleteConfirmation.name}» проекта «{selectedProjectName}» будут безвозвратно удалены.</p>
        </div>
      {/if}
      <div class="login-error-actions system-confirm-actions">
        <Dialog.CloseTrigger class="btn preset-tonal" type="button">Отмена</Dialog.CloseTrigger>
        <button class="btn preset-filled-error-500" type="button" disabled={backupDeletePending || (!backupDeleteConfirmation?.deleteFiles && !backupDeleteConfirmation?.deleteDatabases?.length)} onclick={deleteBackup}>Удалить</button>
      </div>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(backupCommentDialog)} onOpenChange={({ open }) => { if (!open && !backupCommentPending) backupCommentDialog = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">Изменить бэкап</Dialog.Title>
      {#if backupCommentDialog}<label class="label backup-comment-edit"><span class="label-text">Комментарий</span><textarea class="textarea" rows="2" bind:value={backupCommentDialog.comment}></textarea></label>{/if}
      <div class="login-error-actions"><Dialog.CloseTrigger class="btn preset-tonal" type="button" disabled={backupCommentPending}>Отмена</Dialog.CloseTrigger><button class="btn preset-filled-primary-500" type="button" disabled={backupCommentPending} onclick={saveBackupComment}>{backupCommentPending ? 'Сохраняем…' : 'Сохранить'}</button></div>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(backupCreateDialog)} onOpenChange={({ open }) => { if (!open && !backupCreatePending) backupCreateDialog = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog backup-create-dialog card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">Создать бэкап</Dialog.Title>
      {#if backupCreateDialog}
        <div class="backup-create-content">
          <div class="backup-checkbox-row" aria-label="Состав бэкапа"><label><input class="checkbox" type="checkbox" checked={backupCreateDialog.database} onchange={(event) => { backupCreateDialog = { ...backupCreateDialog, database: event.currentTarget.checked }; }} />БД</label><label><input class="checkbox" type="checkbox" checked={backupCreateDialog.files} onchange={(event) => { backupCreateDialog = { ...backupCreateDialog, files: event.currentTarget.checked }; }} />Файлы</label></div>
          <div class="backup-create-columns">
            <div class="backup-create-left">
              <label class="label"><span class="label-text">Хранилище</span><Combobox collection={backupStorageCollection} value={[backupCreateDialog.location]} openOnClick onValueChange={(details) => { backupCreateDialog = { ...backupCreateDialog, location: details.value[0] ?? '' }; }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" aria-label="Хранилище бэкапа" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each backupStorageOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
              <label class="label"><span class="label-text">Стратегия</span><Combobox collection={backupCreateStrategyCollection} value={[backupCreateDialog.strategy]} openOnClick onValueChange={(details) => { backupCreateDialog = { ...backupCreateDialog, strategy: details.value[0] ?? '' }; }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" aria-label="Стратегия бэкапа" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each backupCreateStrategyOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
              {#if backupCreateDialog.files}<div class="backup-strategy-contents"><p>Файлы включают:</p>{#if selectedBackupCreateStrategy?.include?.length}<ul>{#each selectedBackupCreateStrategy.include as pattern}<li><code>{pattern}</code></li>{/each}</ul>{:else}<ul><li>Все файлы и каталоги проекта</li></ul>{/if}<p>Из включённого исключены:</p>{#if selectedBackupCreateStrategy?.exclude?.length}<ul>{#each selectedBackupCreateStrategy.exclude as pattern}<li><code>{pattern}</code></li>{/each}</ul>{:else}<ul><li>Исключений нет</li></ul>{/if}</div>{/if}
              {#if backupCreateDialog.database}<div class="backup-strategy-contents"><p>БД включает:</p>{#if selectedBackupCreateStrategy?.databaseInclude?.length}<ul>{#each selectedBackupCreateStrategy.databaseInclude as pattern}<li><code>{pattern}</code></li>{/each}</ul>{:else}<ul><li>Все таблицы</li></ul>{/if}<p>Из включённого исключены:</p>{#if selectedBackupCreateStrategy?.databaseExclude?.length}<ul>{#each selectedBackupCreateStrategy.databaseExclude as pattern}<li><code>{pattern}</code></li>{/each}</ul>{:else}<ul><li>Исключений нет</li></ul>{/if}</div>{/if}
            </div>
            <div class="backup-create-right">
              {#if backupCreateDialog.database}<fieldset class="backup-database-options"><legend>Базы данных</legend><div class="backup-checkbox-row"><label><input class="checkbox" type="checkbox" checked={backupCreateDialog.mysql} onchange={(event) => { backupCreateDialog = { ...backupCreateDialog, mysql: event.currentTarget.checked }; }} />MySQL</label><label><input class="checkbox" type="checkbox" checked={backupCreateDialog.postgres} onchange={(event) => { backupCreateDialog = { ...backupCreateDialog, postgres: event.currentTarget.checked }; }} />PostgreSQL</label></div></fieldset>{/if}
              {#if backupCreateDialog.files}<fieldset class="backup-database-options"><legend>Файлы</legend><div class="backup-files-column"><label class="label"><span class="label-text">Сжатие</span><Combobox collection={backupCompressionCollection} value={[backupCreateDialog.compress]} openOnClick onValueChange={(details) => { backupCreateDialog = { ...backupCreateDialog, compress: details.value[0] ?? '' }; }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" aria-label="Сжатие файлового бэкапа" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each backupCompressionOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label><label class="label"><span class="label-text">Размер тома <Tooltip positioning={{ placement: 'right' }}><Tooltip.Trigger class="security-help" aria-label="О размере тома"><CircleHelp size={17} aria-hidden="true" /></Tooltip.Trigger><Tooltip.Positioner><Tooltip.Content class="security-tooltip card preset-filled-surface-900-100 shadow-xl">Максимальный размер части архива. Не заполняйте одновременно с количеством томов.</Tooltip.Content></Tooltip.Positioner></Tooltip></span><input class="input" type="text" placeholder="например, 10K" value={backupCreateDialog.chunkSize} disabled={backupCreateDialog.chunkCount !== ''} oninput={(event) => { backupCreateDialog = { ...backupCreateDialog, chunkSize: event.currentTarget.value }; }} /></label><label class="label"><span class="label-text">Количество томов</span><input class="input" type="number" min="2" step="1" placeholder="не задано" value={backupCreateDialog.chunkCount} disabled={backupCreateDialog.chunkSize !== ''} oninput={(event) => { backupCreateDialog = { ...backupCreateDialog, chunkCount: event.currentTarget.value }; }} /></label></div></fieldset>{/if}
              {#if !backupCreateDialog.database && !backupCreateDialog.files}<p class="backup-create-hint">Выберите хотя бы один тип данных для создания бэкапа.</p>{/if}
              {#if backupCreateDialog.database && !backupCreateDialog.mysql && !backupCreateDialog.postgres}<p class="backup-create-hint">Выберите хотя бы одну базу данных.</p>{/if}
            </div>
          </div>
          <label class="label backup-comment-field"><span class="label-text">Комментарий</span><textarea class="textarea" rows="2" bind:value={backupCreateDialog.comment}></textarea></label>
        </div>
      {/if}
      <div class="login-error-actions system-confirm-actions">
        <Dialog.CloseTrigger class="btn preset-tonal" type="button" disabled={backupCreatePending}>Отмена</Dialog.CloseTrigger>
        <button class="btn preset-filled-primary-500" type="button" disabled={backupCreatePending || (!backupCreateDialog?.database && !backupCreateDialog?.files) || (backupCreateDialog?.database && !backupCreateDialog?.mysql && !backupCreateDialog?.postgres)} onclick={createBackup}>{backupCreatePending ? 'Создаём…' : 'Создать'}</button>
      </div>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(scheduleDialog)} onOpenChange={({ open }) => { if (!open && !scheduleSaving) scheduleDialog = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog scheduler-dialog card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">{scheduleDialog?.index === null ? 'Добавить команду' : 'Изменить команду'}</Dialog.Title>
      {#if scheduleDialog}<form class="scheduler-form" onsubmit={(event) => { event.preventDefault(); saveScheduleItem(); }}>
        <label class="scheduler-enabled-option"><input class="checkbox" type="checkbox" bind:checked={scheduleDialog.enabled} />Включена</label>
        <div class="cron-heading"><span>Расписание</span>
          <Tooltip positioning={{ placement: 'right' }}><Tooltip.Trigger class="security-help" type="button" aria-label="Синтаксис cron"><CircleHelp size={17} aria-hidden="true" /></Tooltip.Trigger><Tooltip.Positioner><Tooltip.Content class="security-tooltip cron-tooltip card preset-filled-surface-900-100 shadow-xl">Пять полей: минута (0–59), час (0–23), день месяца (1–31), месяц (1–12), день недели (0–7). Используйте * для любого значения, запятые для списка, дефис для диапазона и / для шага.</Tooltip.Content></Tooltip.Positioner></Tooltip>
          <details class="cron-templates"><summary class="btn btn-sm preset-tonal">Шаблоны</summary><div class="cron-template-menu card preset-filled-surface-100-900 shadow-xl">{#each cronTemplates as [value, label]}<button type="button" onclick={() => applyCronTemplate(value)}><span>{label}</span><code>{value}</code></button>{/each}</div></details>
        </div>
        <div class="cron-fields">{#each ['Минута', 'Час', 'День', 'Месяц', 'День недели'] as placeholder, index}<input class="input" required aria-label={placeholder} {placeholder} value={scheduleDialog.cron[index]} oninput={(event) => { scheduleDialog.cron[index] = event.currentTarget.value; scheduleDialog = { ...scheduleDialog }; }} />{/each}</div>
        <label class="label"><span class="label-text">Команда</span><input class="input" required placeholder="Например, php artisan schedule:run" bind:value={scheduleDialog.command} /></label>
        <label class="label"><span class="label-text">Рабочая папка</span><input class="input" placeholder="Например, app (необязательно)" bind:value={scheduleDialog.workingDirectory} /></label>
        <div class="login-error-actions"><Dialog.CloseTrigger class="btn preset-tonal" type="button" disabled={scheduleSaving}>Отмена</Dialog.CloseTrigger><button class="btn preset-filled-primary-500" type="submit" disabled={scheduleSaving || scheduleDialog.cron.some((value) => !value.trim()) || !scheduleDialog.command.trim()}>{scheduleSaving ? 'Сохраняем…' : 'Сохранить'}</button></div>
      </form>{/if}
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(hookToggleConfirmation)} onOpenChange={({ open }) => { if (!open && !hookToggling) hookToggleConfirmation = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">{hookToggleConfirmation?.enabled ? 'Выключить хук?' : 'Включить хук?'}</Dialog.Title>
      {#if hookToggleConfirmation}<Dialog.Description class="login-error-description">Хук «{hookToggleConfirmation.hook}» будет {hookToggleConfirmation.enabled ? 'выключен: к имени файла будет добавлена точка' : 'включен: точка будет убрана из имени файла'}.</Dialog.Description>{/if}
      <div class="login-error-actions system-confirm-actions"><Dialog.CloseTrigger class="btn preset-tonal" type="button" disabled={hookToggling}>Отмена</Dialog.CloseTrigger><button class="btn preset-filled-primary-500" type="button" disabled={hookToggling} onclick={() => toggleHookItem(hookToggleConfirmation)}>{hookToggling ? 'Сохраняем…' : (hookToggleConfirmation?.enabled ? 'Выключить' : 'Включить')}</button></div>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(hookDeleteConfirmation)} onOpenChange={({ open }) => { if (!open && !hookDeleting) hookDeleteConfirmation = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog error-alert card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">Удалить хук?</Dialog.Title>
      {#if hookDeleteConfirmation}<Dialog.Description class="login-error-description">Хук «{hookDeleteConfirmation.hook}» будет удалён с диска. Это действие необратимо.</Dialog.Description>{/if}
      <div class="login-error-actions system-confirm-actions"><Dialog.CloseTrigger class="btn preset-tonal" type="button" disabled={hookDeleting}>Отмена</Dialog.CloseTrigger><button class="btn preset-filled-error-500" type="button" disabled={hookDeleting} onclick={() => deleteHookItem(hookDeleteConfirmation)}>{hookDeleting ? 'Удаляем…' : 'Удалить'}</button></div>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(scheduleToggleConfirmation)} onOpenChange={({ open }) => { if (!open && !scheduleToggling) scheduleToggleConfirmation = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">{scheduleToggleConfirmation?.enabled ? 'Выключить команду?' : 'Включить команду?'}</Dialog.Title>
      {#if scheduleToggleConfirmation}<Dialog.Description class="login-error-description">Команда «{scheduleToggleConfirmation.command}» будет {scheduleToggleConfirmation.enabled ? 'выключена и перестанет запускаться по расписанию' : 'включена для запуска по расписанию'}.</Dialog.Description>{/if}
      <div class="login-error-actions system-confirm-actions"><Dialog.CloseTrigger class="btn preset-tonal" type="button" disabled={scheduleToggling}>Отмена</Dialog.CloseTrigger><button class="btn preset-filled-primary-500" type="button" disabled={scheduleToggling} onclick={() => toggleScheduleItem(scheduleToggleConfirmation)}>{scheduleToggling ? 'Сохраняем…' : (scheduleToggleConfirmation?.enabled ? 'Выключить' : 'Включить')}</button></div>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(scheduleDeleteConfirmation)} onOpenChange={({ open }) => { if (!open && !scheduleDeleting) scheduleDeleteConfirmation = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog error-alert card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">Удалить команду?</Dialog.Title>
      {#if scheduleDeleteConfirmation}<Dialog.Description class="login-error-description">Команда «{scheduleDeleteConfirmation.command}» будет удалена из расписания проекта. Это действие нельзя отменить.</Dialog.Description>{/if}
      <div class="login-error-actions system-confirm-actions">
        <Dialog.CloseTrigger class="btn preset-tonal" type="button" disabled={scheduleDeleting}>Отмена</Dialog.CloseTrigger>
        <button class="btn preset-filled-error-500" type="button" disabled={scheduleDeleting} onclick={() => deleteScheduleItem(scheduleDeleteConfirmation)}>{scheduleDeleting ? 'Удаляем…' : 'Удалить'}</button>
      </div>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(systemConfirmation)} onOpenChange={({ open }) => { if (!open) systemConfirmation = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">
        {systemConfirmation?.action === 'stop' ? 'Остановить панель?' : 'Перезапустить панель?'}
      </Dialog.Title>
      <Dialog.Description class="login-error-description">
        {#if systemConfirmation?.action === 'stop'}
          Вы останавливаете компонент, необходимый для работы админки. После подтверждения админка прекратит работу, пока система не будет снова запущена.
        {:else}
          Админка сейчас уйдёт на перезапуск и автоматически восстановит работу через несколько секунд.
        {/if}
      </Dialog.Description>
      <div class="login-error-actions system-confirm-actions">
        <Dialog.CloseTrigger class="btn preset-tonal" type="button">Отмена</Dialog.CloseTrigger>
        <button class="btn preset-filled-primary-500" type="button" onclick={() => { const action = systemConfirmation; systemConfirmation = null; if (action) systemAction(action.action, action.service); }}>Продолжить</button>
      </div>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(queueConfirmation)} onOpenChange={({ open }) => { if (!open) queueConfirmation = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">Удалить элемент очереди?</Dialog.Title>
      <Dialog.Description class="login-error-description">
        Элемент «{queueConfirmation?.code}» будет безвозвратно удалён из очереди default.
      </Dialog.Description>
      <div class="login-error-actions system-confirm-actions">
        <Dialog.CloseTrigger class="btn preset-tonal" type="button">Отмена</Dialog.CloseTrigger>
        <button class="btn preset-filled-error-500" type="button" onclick={() => queueConfirmation && deleteQueueItem(queueConfirmation)}>Удалить</button>
      </div>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={systemPending} role="alertdialog" closeOnEscape={false} closeOnInteractOutside={false}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog card preset-filled-surface-100-900 shadow-2xl system-wait-dialog">
      <div class="system-spinner" aria-hidden="true"></div>
      <Dialog.Title class="login-error-title">Выполняется операция</Dialog.Title>
      <Dialog.Description class="login-error-description">{systemPendingMessage}</Dialog.Description>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog
  open={Boolean(error)}
  role={errorStatus >= 500 ? 'alertdialog' : 'dialog'}
  onOpenChange={({ open }) => { if (!open) error = ''; }}
>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class={`login-error-dialog card preset-filled-surface-100-900 shadow-2xl${errorStatus >= 500 ? ' error-alert' : ''}`}>
      <Dialog.Title class="login-error-title">{errorStatus >= 500 ? 'Ошибка сервера' : errorTitle}</Dialog.Title>
      <Dialog.Description class="login-error-description">{error}</Dialog.Description>
      <div class="login-error-actions">
        <Dialog.CloseTrigger class={`btn preset-filled-primary-500${errorStatus >= 500 ? ' error-alert-button' : ''}`} type="button">Закрыть</Dialog.CloseTrigger>
      </div>
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>
