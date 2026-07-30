<script>
  import { onMount } from 'svelte';
  import { Combobox, Dialog, Tooltip, useListCollection } from '@skeletonlabs/skeleton-svelte';
  import { Archive, Bell, CircleHelp, Copy, ExternalLink, Lock, Pencil, Play, Plus, Power, RotateCw, Save, Square, Trash2 } from '@lucide/svelte';
  import { micromark } from 'micromark';
  import BackupDateFilter from './BackupDateFilter.svelte';
  import { createPanelUser, createProject, deletePanelUser, getLogs, getProjectBackups, getProjectOptions, getProjects, getProjectsSettings, getSecuritySettings, getSystemStatus, getUsersSettings, renameProject, rotatePanelUserPassword, runProjectAction, runSystemAction, saveProjectNotes, saveProjectSecurity, saveProjectsSettings, saveSecuritySettings, updatePanelUser } from './api.js';

  const THEME_KEY = 'docker-cli-panel-color-theme';
  const MODE_KEY = 'docker-cli-panel-theme';
  const FONT_KEY = 'docker-cli-panel-font';
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
  const projectDetailTabs = ['info', 'notes', 'security', 'backups', 'journal'];
  const fonts = [
    { value: 'ubuntu', label: 'Ubuntu Regular' },
    { value: 'noto', label: 'Noto Sans' },
  ];
  const fontCollection = useListCollection({ items: fonts });
  const logTypes = [{ value: 'queue', label: 'Очередь' }];
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
  let notificationsOpen = false;
  let notifications = [];
  let notificationsInitialized = false;
  const knownNotificationFiles = new Set();
  let theme = 'vox';
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
  let logType = 'queue';
  let logProject = 'all';
  let logStatus = 'all';
  let logQueueItem = '';
  let logItemCode = '';
  let logTaskCode = '';
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
  let systemPendingMessage = '';
  let queuedOperationNotice = '';
  let systemConfirmation = null;
  let projectConfirmation = null;
  let projectContextMenu = null;
  let projectAddDialog = null;
  let projectRenameDialog = null;
  let projectRenaming = false;
  let projectAddOptions = { locations: [], languages: [], frameworks: {} };
  let projectLocationCollection = useListCollection({ items: [] });
  let projectLanguageCollection = useListCollection({ items: [] });
  let projectFrameworkCollection = useListCollection({ items: [] });
  let projectAdding = false;
  let backupItems = [];
  let backupTotal = 0;
  let backupPage = 1;
  let backupPageSize = 25;
  let backupName = '';
  let backupComposition = 'all';
  let backupDatabase = 'all';
  let backupDateFrom = '';
  let backupDateTo = '';
  let backupSort = 'date';
  let backupDirection = 'desc';
  let backupsLoading = false;
  let backupFilterTimer = null;
  let backupRequestId = 0;
  const backupCompositionOptions = [{ value: 'all', label: 'Любой состав' }, { value: 'database', label: 'БД' }, { value: 'files', label: 'Файлы' }, { value: 'database-files', label: 'БД и файлы' }];
  const backupDatabaseOptions = [{ value: 'all', label: 'Любая СУБД' }, { value: 'mysql', label: 'MySQL' }];
  const backupCompositionCollection = useListCollection({ items: backupCompositionOptions });
  const backupDatabaseCollection = useListCollection({ items: backupDatabaseOptions });
  let notesProjectName = '';
  let noteTags = [];
  let noteTagInput = '';
  let noteDescription = '';
  let notesSaving = false;
  let securitySaving = false;
  let maximumSessionHours = 8;
  let settingsLoading = false;
  let settingsSaving = false;
  let projectLocations = [{ path: '', default: true }];
  let projectSettingsLoading = false;
  let projectSettingsSaving = false;
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
  $: projectLocationCollection = useListCollection({ items: projectAddOptions.locations.map((item) => ({ value: item.code, label: `${item.code} — ${item.path}` })) });
  $: projectLanguageCollection = useListCollection({ items: projectAddOptions.languages.map((item) => ({ value: item.code, label: item.name })) });
  $: projectFrameworkCollection = useListCollection({ items: (projectAddOptions.frameworks[projectAddDialog?.language] || []).map((item) => ({ value: item.code, label: item.name })) });

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

  function journalFilterHash(projectJournal = false) {
    const path = projectJournal ? projectHash(selectedProjectName, 'journal') : '#/journal';
    const parameters = new URLSearchParams();
    if (logType !== 'queue') parameters.set('type', logType);
    if (!projectJournal && logProject !== 'all') parameters.set('project', logProject);
    if (logStatus !== 'all') parameters.set('status', logStatus);
    if (logQueueItem) parameters.set('queue_item', logQueueItem);
    if (logItemCode) parameters.set('item_code', logItemCode);
    if (logTaskCode) parameters.set('task_code', logTaskCode);
    const query = parameters.toString().replaceAll('%2C', ',');
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
    const type = scalar('type');
    const project = scalar('project');
    const status = scalar('status');
    const queueItem = scalar('queue_item');
    const itemCode = scalar('item_code');
    const taskCode = scalar('task_code');

    logType = logTypes.some((item) => item.value === type) ? type : 'queue';
    logProject = !projectJournal && project && (!projects.length || projects.some((item) => item.name === project)) ? project : 'all';
    logStatus = logStatuses.some((item) => item.value === status) ? status : 'all';
    logQueueItem = queueItem && validText(queueItem) ? queueItem : '';
    logItemCode = itemCode && validText(itemCode) ? itemCode : '';
    logTaskCode = taskCode && validText(taskCode) ? taskCode : '';
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
    if (segments.length === 2 && segments[0] === 'settings' && ['projects', 'users', 'security'].includes(segments[1])) {
      activeSection = 'settings';
      settingsTab = segments[1];
      selectedProjectName = '';
      if (settingsTab === 'projects') loadProjectsSettings();
      else if (settingsTab === 'users') loadUsersSettings();
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
    if (tab === 'backups') loadProjectBackups();
    if (segments.length !== 3 || !projectDetailTabs.includes(segments[2])) navigateToProject(projectName, tab);
  }

  async function loadProjectBackups() {
    if (!authenticated || !selectedProjectName) return;
    const requestId = ++backupRequestId;
    backupsLoading = true;
    projectsError = '';
    try {
      const data = await getProjectBackups(api, selectedProjectName, { page: String(backupPage), pageSize: String(backupPageSize), name: backupName, composition: backupComposition, database: backupDatabase, dateFrom: backupDateFrom, dateTo: backupDateTo, sort: backupSort, direction: backupDirection });
      if (requestId !== backupRequestId) return;
      backupItems = Array.isArray(data.items) ? data.items : [];
      backupTotal = Number(data.total) || 0;
    } catch (cause) {
      if (requestId === backupRequestId) projectsError = cause instanceof Error ? cause.message : 'Не удалось загрузить бэкапы.';
    } finally {
      if (requestId === backupRequestId) backupsLoading = false;
    }
  }

  function changeBackupFilter(field, value) {
    if (field === 'name') backupName = value;
    else if (field === 'composition') backupComposition = value;
    else if (field === 'database') backupDatabase = value;
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

  function formatBytes(value) {
    const bytes = Number(value) || 0;
    if (bytes < 1024) return `${bytes} Б`;
    const units = ['КБ', 'МБ', 'ГБ', 'ТБ'];
    const unit = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)) - 1, units.length - 1);
    return `${(bytes / (1024 ** (unit + 1))).toLocaleString('ru-RU', { maximumFractionDigits: 1 })} ${units[unit]}`;
  }

  async function loadLogs() {
    if (!authenticated) return;
    const requestId = ++logRequestId;
    logsLoading = true;
    projectsError = '';
    try {
      const projectJournal = activeSection === 'projects' && projectDetailTab === 'journal';
      const data = await getLogs(api, {
        page: String(logPage), pageSize: String(logPageSize), sort: logSort, direction: logDirection,
        ...(projectJournal ? { project: selectedProjectName } : logProject !== 'all' ? { project: logProject } : {}),
        ...(logStatus !== 'all' ? { status: logStatus } : {}),
        ...(logQueueItem ? { queueItem: logQueueItem } : {}),
        ...(logItemCode ? { itemCode: logItemCode } : {}),
        ...(logTaskCode ? { taskCode: logTaskCode } : {}),
      });
      if (requestId !== logRequestId) return;
      logItems = Array.isArray(data.items) ? data.items : [];
      logTotal = Number(data.total) || 0;
      logProjects = Array.isArray(data.projects) ? data.projects : [];
      logProjectCollection = useListCollection({ items: [{ value: 'all', label: 'Все проекты' }, ...logProjects.map((value) => ({ value, label: value }))] });
      if (!projectJournal && logProject !== 'all' && !logProjects.includes(logProject)) {
        logProject = 'all';
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
    } catch (cause) {
      errorTitle = 'Не удалось загрузить настройки';
      error = cause instanceof Error ? cause.message : 'Не удалось загрузить расположения проектов.';
      errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
    } finally {
      projectSettingsLoading = false;
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
    userContextMenu = { user, x: Math.min(event.clientX, window.innerWidth - 180), y: Math.min(event.clientY, window.innerHeight - 120) };
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

  async function saveProjectLocations() {
    if (projectSettingsSaving || projectLocations.some((location) => !location.path.trim())) return;
    projectSettingsSaving = true;
    try {
      const data = await saveProjectsSettings(api, projectLocations.map((location) => ({ ...location, path: location.path.trim() })));
      projectLocations = data.locations;
    } catch (cause) {
      errorTitle = 'Не удалось сохранить настройки';
      error = cause instanceof Error ? cause.message : 'Не удалось сохранить расположения проектов.';
      errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
    } finally {
      projectSettingsSaving = false;
    }
  }

  async function loadSecuritySettings() {
    if (!authenticated || settingsLoading) return;
    settingsLoading = true;
    try {
      const data = await getSecuritySettings(api);
      maximumSessionHours = Number(data.maximumSessionHours) || 8;
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
      const data = await saveSecuritySettings(api, hours);
      maximumSessionHours = data.maximumSessionHours;
    } catch (cause) {
      errorTitle = 'Не удалось сохранить настройки';
      error = cause instanceof Error ? cause.message : 'Не удалось сохранить настройки безопасности.';
      errorStatus = cause instanceof Error && 'status' in cause && typeof cause.status === 'number' ? cause.status : 0;
    } finally {
      settingsSaving = false;
    }
  }

  function changeLogProject(value) {
    logProject = value || 'all';
    logPage = 1;
    syncJournalFilters();
    loadLogs();
  }

  function changeLogStatus(value) {
    logStatus = value || 'all';
    logPage = 1;
    syncJournalFilters();
    loadLogs();
  }

  function logStatusLabel(value) {
    return logStatuses.find((status) => status.value === value)?.label || formatLogValue(value);
  }

  function changeTextLogFilter(field, value) {
    if (field === 'queueItem') logQueueItem = value;
    else if (field === 'itemCode') logItemCode = value;
    else logTaskCode = value;
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
    projectContextMenu = null;
    userContextMenu = null;
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

  async function openProjectAddDialog() {
    try {
      projectAddOptions = await getProjectOptions(api);
      const location = projectAddOptions.locations.find((item) => item.default) || projectAddOptions.locations[0];
      const language = projectAddOptions.languages[0];
      projectAddDialog = { code: '', location: location?.code || '', language: language?.code || '', framework: projectAddOptions.frameworks[language?.code]?.[0]?.code || '' };
    } catch (cause) {
      errorTitle = 'Не удалось открыть добавление проекта';
      error = cause instanceof Error ? cause.message : 'Не удалось загрузить параметры проекта.';
    }
  }

  async function addProject() {
    if (!projectAddDialog) return;
    projectAdding = true;
    try {
      const data = await createProject(api, projectAddDialog);
      projects = data.projects;
      projectAddDialog = null;
      notifyQueuedOperation('Добавление проекта');
    } catch (cause) {
      errorTitle = 'Не удалось добавить проект';
      error = cause instanceof Error ? cause.message : 'Не удалось добавить проект.';
    }
    finally { projectAdding = false; }
  }

  async function submitProjectRename() {
    if (!projectRenameDialog || projectRenaming) return;
    projectRenaming = true;
    try {
      const data = await renameProject(api, projectRenameDialog.project, projectRenameDialog.code);
      projects = data.projects;
      projectRenameDialog = null;
      notifyQueuedOperation('Переименование проекта');
    } catch (cause) {
      errorTitle = 'Не удалось переименовать проект';
      error = cause instanceof Error ? cause.message : 'Не удалось переименовать проект.';
    } finally { projectRenaming = false; }
  }

  async function api(path, options = {}) {
    const response = await fetch(path, {
      ...options,
      headers: {
        'Content-Type': 'application/json',
        ...(options.headers || {}),
      },
    });
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
    if (newNotifications.length === 0 || !('Notification' in window)) return;
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
  onkeydown={(event) => { if (event.key === 'Escape') { themeOpen = false; notificationsOpen = false; profileOpen = false; systemOpen = false; queueOpen = false; } }}
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
              {#if systemServices.length === 0}<p class="system-empty">Сервисы не найдены</p>{/if}
              {#each systemServices as service (service.name)}
                <div class="system-service">
                  <span class={`system-dot ${service.running ? 'running' : 'stopped'}`} aria-hidden="true"></span>
                  <span class="system-service-name" title={service.image}>{service.name}</span>
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
          <button class="btn preset-tonal" type="button" aria-expanded={profileOpen} onclick={() => { profileOpen = !profileOpen; themeOpen = false; notificationsOpen = false; }}>{currentLogin}</button>
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
                        <span class="project-name"><strong>{project.name}</strong>{#if project.protected}<Lock size={14} aria-label="Защищённый проект" />{/if}</span>
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
                <a class:active={projectDetailTab === 'journal'} class="project-detail-tab" href={projectHash(selectedProject.name, 'journal')} aria-current={projectDetailTab === 'journal' ? 'page' : undefined}>Журнал</a>
              </nav>
              <div class="project-details-scroll" class:table-tab={projectDetailTab === 'journal' || projectDetailTab === 'backups'}>
                {#if projectDetailTab === 'info'}
                <section class="project-tab-content card preset-filled-surface-100-900" aria-label="Общее">
                  <dl class="project-fields">
                    <div><dt>Название</dt><dd>{selectedProject.name}</dd></div>
                    <div><dt>Язык</dt><dd>{selectedProject.language?.name || 'Не указан'}</dd></div>
                    <div><dt>Фреймворк</dt><dd>{selectedProject.framework?.name || 'Без фреймворка'}</dd></div>
                    <div><dt>Статус</dt><dd class:enabled={selectedProject.enabled} class="status-value"><i></i>{selectedProject.enabled ? 'Включен' : 'Выключен'}</dd></div>
                    <div><dt>Основной хост</dt><dd>{#if selectedProject.url}<a class="project-host" href={selectedProject.url} target="_blank" rel="noreferrer">{selectedProject.url}<ExternalLink size={14} aria-hidden="true" /></a>{:else}Не указан{/if}</dd></div>
                  </dl>
                  <div class="project-general-actions">
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
                <section class="project-log-view backup-view" aria-label={`Бэкапы проекта ${selectedProject.name}`}>
                  <div class="log-toolbar card preset-filled-surface-100-900">
                    <label><span>Название</span><span class="log-text-filter"><input type="search" value={backupName} oninput={(event) => changeBackupFilter('name', event.currentTarget.value)} />{#if backupName}<button type="button" aria-label="Сбросить название" onclick={() => changeBackupFilter('name', '')}>×</button>{/if}</span></label>
                    <label><span>Состав</span><Combobox collection={backupCompositionCollection} value={[backupComposition]} openOnClick onValueChange={(details) => changeBackupFilter('composition', details.value[0] || 'all')}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each backupCompositionOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
                    <label><span>Тип СУБД</span><Combobox collection={backupDatabaseCollection} value={[backupDatabase]} openOnClick onValueChange={(details) => changeBackupFilter('database', details.value[0] || 'all')}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each backupDatabaseOptions as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
                    <BackupDateFilter label="Дата от" value={backupDateFrom} onchange={(value) => changeBackupFilter('dateFrom', value)} />
                    <BackupDateFilter label="Дата до" value={backupDateTo} onchange={(value) => changeBackupFilter('dateTo', value)} />
                  </div>
                  <div class="log-table-wrap card preset-filled-surface-100-900">
                    <table class="table log-table backup-table">
                      <thead><tr>{#each [['name', 'Название'], ['date', 'Дата'], ['composition', 'Состав'], ['size', 'Размер'], ['database', 'Тип СУБД']] as [field, label]}<th><button type="button" onclick={() => sortBackups(field)}>{label}<span aria-hidden="true">{backupSort === field ? (backupDirection === 'asc' ? ' ↑' : ' ↓') : ' ↕'}</span></button></th>{/each}</tr></thead>
                      <tbody>
                        {#if backupsLoading}<tr><td colspan="5" class="log-empty animate-pulse">Загрузка…</td></tr>
                        {:else if backupItems.length === 0}<tr><td colspan="5" class="log-empty">Бэкапы не найдены</td></tr>
                        {:else}{#each backupItems as item}<tr><td>{item.name}</td><td>{formatQueueDate(item.date)}</td><td>{item.composition}</td><td>{formatBytes(item.size)}</td><td>{item.database || '—'}</td></tr>{/each}{/if}
                      </tbody>
                    </table>
                  </div>
                  <footer class="log-pagination">
                    <span>{backupTotal ? `${(backupPage - 1) * backupPageSize + 1}–${Math.min(backupPage * backupPageSize, backupTotal)} из ${backupTotal}` : '0 бэкапов'}</span>
                    <div class="log-pagination-controls"><button class="btn btn-sm preset-tonal" type="button" disabled={backupPage === 1 || backupsLoading} onclick={() => { backupPage -= 1; loadProjectBackups(); }}>Назад</button><button class="btn btn-sm preset-tonal" type="button" disabled={backupPage >= Math.ceil(backupTotal / backupPageSize) || backupsLoading} onclick={() => { backupPage += 1; loadProjectBackups(); }}>Вперёд</button></div>
                    <div class="log-page-size" aria-label="Количество бэкапов на странице"><Combobox collection={pageSizeCollection} value={[String(backupPageSize)]} openOnClick onValueChange={(details) => { if (details.value[0]) { backupPageSize = Number(details.value[0]); backupPage = 1; loadProjectBackups(); } }}><Combobox.Control class="page-size-control font-combobox-control"><Combobox.Input class="font-combobox-input" aria-label="Количество бэкапов на странице" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each [25, 50, 100] as value}<Combobox.Item item={{ value: String(value), label: String(value) }} class="font-combobox-item"><Combobox.ItemText>{value}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></div>
                  </footer>
                </section>
                {:else}
                <section class="project-log-view" aria-label={`Журнал проекта ${selectedProject.name}`}>
                  <div class="log-toolbar card preset-filled-surface-100-900">
                    <label>
                      <span>Тип записи</span>
                      <Combobox collection={logTypeCollection} value={[logType]} openOnClick>
                        <Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control>
                        <Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each logTypes as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner>
                      </Combobox>
                    </label>
                    <label>
                      <span>Статус</span>
                      <Combobox collection={logStatusCollection} value={[logStatus]} openOnClick onValueChange={(details) => changeLogStatus(details.value[0] || 'all')}>
                        <Combobox.Control class="font-combobox-control status-combobox-control">{#if logStatus !== 'all'}<span class={`queue-dot status-${logStatus}`} aria-hidden="true"></span>{/if}<Combobox.Input class="font-combobox-input" readonly />{#if logStatus !== 'all'}<button class="log-filter-clear" type="button" aria-label="Сбросить статус" onclick={(event) => { event.stopPropagation(); changeLogStatus('all'); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control>
                        <Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each logStatuses as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText><span class="log-status-option">{#if item.value !== 'all'}<span class={`queue-dot status-${item.value}`} aria-hidden="true"></span>{/if}{item.label}</span></Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner>
                      </Combobox>
                    </label>
                    {#each [['queueItem', 'Элемент очереди', logQueueItem], ['itemCode', 'Код элемента', logItemCode], ['taskCode', 'Задача', logTaskCode]] as [field, label, value]}
                      <label><span>{label}</span><span class="log-text-filter"><input value={value} oninput={(event) => changeTextLogFilter(field, event.currentTarget.value)} />{#if value}<button type="button" aria-label={`Сбросить фильтр «${label}»`} onclick={() => changeTextLogFilter(field, '')}>×</button>{/if}</span></label>
                    {/each}
                  </div>
                  <div class="log-table-wrap card preset-filled-surface-100-900">
                    <table class="table log-table project-log-table">
                      <thead><tr>{#each [['timestamp', 'Время'], ['queueItem', 'Элемент очереди'], ['itemCode', 'Код элемента'], ['queueCode', 'Очередь'], ['status', 'Статус'], ['taskCode', 'Задача'], ['result', 'Результат'], ['message', 'Сообщение']] as [field, label]}<th><button type="button" onclick={() => sortLogs(field)}>{label}<span aria-hidden="true">{logSort === field ? (logDirection === 'asc' ? ' ↑' : ' ↓') : ' ↕'}</span></button></th>{/each}</tr></thead>
                      <tbody>
                        {#if logsLoading}<tr><td colspan="8" class="log-empty animate-pulse">Загрузка…</td></tr>
                        {:else if logItems.length === 0}<tr><td colspan="8" class="log-empty">Записей нет</td></tr>
                        {:else}{#each logItems as item}<tr><td>{formatQueueDate(item.timestamp)}</td><td><button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('queueItem', item.queueItem)}>{formatLogValue(item.queueItem)}</button></td><td><button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('itemCode', item.itemCode)}>{formatLogValue(item.itemCode)}</button></td><td>{formatLogValue(item.queueCode)}</td><td>{#if item.status}<button class="log-filter-link log-status-link" type="button" onclick={() => changeLogStatus(item.status)}><span class={`queue-dot status-${item.status}`} aria-hidden="true"></span>{logStatusLabel(item.status)}</button>{:else}—{/if}</td><td>{#if item.taskCode}<button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('taskCode', item.taskCode)}>{item.taskCode}</button>{:else}—{/if}</td><td>{formatLogValue(item.result)}</td><td>{formatLogValue(item.message)}</td></tr>{/each}{/if}
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
          <section class="log-view" aria-label="Журнал">
            <div class="log-toolbar card preset-filled-surface-100-900">
              <label>
                <span>Тип записи</span>
                <Combobox collection={logTypeCollection} value={[logType]} openOnClick>
                  <Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control>
                  <Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each logTypes as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner>
                </Combobox>
              </label>
              <label>
                <span>Проект</span>
                <Combobox collection={logProjectCollection} value={[logProject]} openOnClick onValueChange={(details) => changeLogProject(details.value[0] || 'all')}>
                  <Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly />{#if logProject !== 'all'}<button class="log-filter-clear" type="button" aria-label="Сбросить проект" onclick={(event) => { event.stopPropagation(); changeLogProject('all'); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control>
                  <Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each [{ value: 'all', label: 'Все проекты' }, ...logProjects.map((value) => ({ value, label: value }))] as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner>
                </Combobox>
              </label>
              <label>
                <span>Статус</span>
                <Combobox collection={logStatusCollection} value={[logStatus]} openOnClick onValueChange={(details) => changeLogStatus(details.value[0] || 'all')}>
                  <Combobox.Control class="font-combobox-control status-combobox-control">{#if logStatus !== 'all'}<span class={`queue-dot status-${logStatus}`} aria-hidden="true"></span>{/if}<Combobox.Input class="font-combobox-input" readonly />{#if logStatus !== 'all'}<button class="log-filter-clear" type="button" aria-label="Сбросить статус" onclick={(event) => { event.stopPropagation(); changeLogStatus('all'); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control>
                  <Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each logStatuses as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText><span class="log-status-option">{#if item.value !== 'all'}<span class={`queue-dot status-${item.value}`} aria-hidden="true"></span>{/if}{item.label}</span></Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner>
                </Combobox>
              </label>
              {#each [['queueItem', 'Элемент очереди', logQueueItem], ['itemCode', 'Код элемента', logItemCode], ['taskCode', 'Задача', logTaskCode]] as [field, label, value]}
                <label><span>{label}</span><span class="log-text-filter"><input value={value} oninput={(event) => changeTextLogFilter(field, event.currentTarget.value)} />{#if value}<button type="button" aria-label={`Сбросить фильтр «${label}»`} onclick={() => changeTextLogFilter(field, '')}>×</button>{/if}</span></label>
              {/each}
            </div>
            <div class="log-table-wrap card preset-filled-surface-100-900">
              <table class="table log-table">
                <thead><tr>
                  {#each [['timestamp', 'Время'], ['queueItem', 'Элемент очереди'], ['itemCode', 'Код элемента'], ['project', 'Проект'], ['queueCode', 'Очередь'], ['status', 'Статус'], ['taskCode', 'Задача'], ['result', 'Результат'], ['message', 'Сообщение']] as [field, label]}
                    <th><button type="button" onclick={() => sortLogs(field)}>{label}<span aria-hidden="true">{logSort === field ? (logDirection === 'asc' ? ' ↑' : ' ↓') : ' ↕'}</span></button></th>
                  {/each}
                </tr></thead>
                <tbody>
                  {#if logsLoading}<tr><td colspan="9" class="log-empty animate-pulse">Загрузка…</td></tr>
                  {:else if logItems.length === 0}<tr><td colspan="9" class="log-empty">Записей нет</td></tr>
                  {:else}{#each logItems as item}<tr><td>{formatQueueDate(item.timestamp)}</td><td><button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('queueItem', item.queueItem)}>{formatLogValue(item.queueItem)}</button></td><td><button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('itemCode', item.itemCode)}>{formatLogValue(item.itemCode)}</button></td><td>{#if logRecordProjects(item).length}{#each logRecordProjects(item) as project, index}{#if index}, {/if}<button class="log-filter-link" type="button" onclick={() => changeLogProject(project)}>{project}</button>{/each}{:else}—{/if}</td><td>{formatLogValue(item.queueCode)}</td><td>{#if item.status}<button class="log-filter-link log-status-link" type="button" onclick={() => changeLogStatus(item.status)}><span class={`queue-dot status-${item.status}`} aria-hidden="true"></span>{logStatusLabel(item.status)}</button>{:else}—{/if}</td><td>{#if item.taskCode}<button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('taskCode', item.taskCode)}>{item.taskCode}</button>{:else}—{/if}</td><td>{formatLogValue(item.result)}</td><td>{formatLogValue(item.message)}</td></tr>{/each}{/if}
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
              <a class:active={settingsTab === 'users'} class="project-detail-tab" href="#/settings/users" aria-current={settingsTab === 'users' ? 'page' : undefined}>Пользователи</a>
              <a class:active={settingsTab === 'security'} class="project-detail-tab" href="#/settings/security" aria-current={settingsTab === 'security' ? 'page' : undefined}>Безопасность</a>
            </nav>
            {#if settingsTab === 'projects'}
            <div class="settings-scroll">
              <div class="project-toolbar">
                <button class="btn preset-filled-primary-500" type="button" disabled={projectSettingsLoading || projectSettingsSaving || projectLocations.some((location) => !location.path.trim())} onclick={saveProjectLocations}>
                  <Save size={16} aria-hidden="true" />{projectSettingsSaving ? 'Сохраняем…' : 'Сохранить'}
                </button>
              </div>
              <section class="settings-card locations-card card preset-filled-surface-100-900" aria-label="Расположение">
                <h2>Расположение
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
            </div>
            {:else if settingsTab === 'users'}
            <div class="settings-scroll users-settings-scroll">
              <div class="project-toolbar">
                <button class="btn preset-filled-primary-500" type="button" onclick={() => { userDialog = { create: true, login: '', comments: '' }; }}><Plus size={16} aria-hidden="true" />Добавить</button>
              </div>
              <div class="users-table-wrap card preset-filled-surface-100-900">
                <table class="table users-table">
                  <thead><tr><th>Логин</th><th>Комментарии</th><th aria-label="Действия"></th></tr></thead>
                  <tbody>
                    {#if usersLoading}<tr><td colspan="3" class="log-empty animate-pulse">Загрузка…</td></tr>
                    {:else if users.length === 0}<tr><td colspan="3" class="log-empty">Пользователей нет</td></tr>
                    {:else}{#each users as user (user.login)}
                      <tr oncontextmenu={(event) => openUserContextMenu(event, user)}>
                        <td>{user.login}</td><td>{user.comments || '—'}</td>
                        <td class="user-actions"><button class="btn-icon preset-tonal" type="button" aria-label={`Изменить пользователя ${user.login}`} title="Изменить" onclick={() => { userDialog = { create: false, ...user }; }}><Pencil size={16} aria-hidden="true" /></button><button class="btn-icon preset-tonal" type="button" aria-label={`Удалить пользователя ${user.login}`} title="Удалить" onclick={() => { userDeleteConfirmation = user; }}><Trash2 size={16} aria-hidden="true" /></button></td>
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
            {:else}
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
    <button type="button" role="menuitem" onclick={() => { userDialog = { create: false, ...userContextMenu.user }; userContextMenu = null; }}>Изменить</button>
    <button class="danger" type="button" role="menuitem" onclick={() => { userDeleteConfirmation = userContextMenu.user; userContextMenu = null; }}>Удалить</button>
  </div>
{/if}

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
    <button type="button" role="menuitem" onclick={() => { projectRenameDialog = { project: projectContextMenu.project.name, code: projectContextMenu.project.name }; projectContextMenu = null; }}>
      <Pencil size={16} aria-hidden="true" />Переименовать
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

<Dialog open={Boolean(projectRenameDialog)} onOpenChange={({ open }) => { if (!open && !projectRenaming) projectRenameDialog = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog card preset-filled-surface-100-900 shadow-2xl">
      <Dialog.Title class="login-error-title">Переименовать проект</Dialog.Title>
      {#if projectRenameDialog}
        <form class="project-add-form" onsubmit={(event) => { event.preventDefault(); submitProjectRename(); }}>
          <label class="label"><span class="label-text">Код</span><input class="input" bind:value={projectRenameDialog.code} required pattern="[a-z0-9](?:[a-z0-9-]*[a-z0-9])?" /></label>
          <div class="login-error-actions"><button class="btn preset-tonal" type="button" disabled={projectRenaming} onclick={() => { projectRenameDialog = null; }}>Отмена</button><button class="btn preset-filled-primary-500" type="submit" disabled={projectRenaming || !projectRenameDialog.code}>{projectRenaming ? 'Переименовываем…' : 'Переименовать'}</button></div>
        </form>
      {/if}
    </Dialog.Content>
  </Dialog.Positioner>
</Dialog>

<Dialog open={Boolean(projectAddDialog)} onOpenChange={({ open }) => { if (!open && !projectAdding) projectAddDialog = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class="login-error-dialog card preset-filled-surface-100-900 shadow-2xl">
      {#if projectAddDialog}
      <Dialog.Title class="login-error-title">Добавить проект</Dialog.Title>
      <form class="project-add-form" onsubmit={(event) => { event.preventDefault(); addProject(); }}>
        <label class="label"><span class="label-text">Код (опционально)</span><input class="input" bind:value={projectAddDialog.code} pattern="[a-z0-9](?:[a-z0-9-]*[a-z0-9])?" /></label>
        <label class="label"><span class="label-text">Локация</span><Combobox collection={projectLocationCollection} value={[projectAddDialog.location]} openOnClick onValueChange={(details) => { if (details.value[0]) projectAddDialog.location = details.value[0]; }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly required /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each projectAddOptions.locations.map((location) => ({ value: location.code, label: `${location.code} — ${location.path}` })) as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
        <label class="label"><span class="label-text">Язык</span><Combobox collection={projectLanguageCollection} value={[projectAddDialog.language]} openOnClick onValueChange={(details) => { if (details.value[0]) { projectAddDialog.language = details.value[0]; projectAddDialog.framework = projectAddOptions.frameworks[projectAddDialog.language]?.[0]?.code || ''; } }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each projectAddOptions.languages.map((language) => ({ value: language.code, label: language.name })) as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
        <label class="label"><span class="label-text">Фреймворк</span><Combobox collection={projectFrameworkCollection} value={[projectAddDialog.framework]} openOnClick onValueChange={(details) => { projectAddDialog.framework = details.value[0] ?? ''; }}><Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each (projectAddOptions.frameworks[projectAddDialog.language] || []).map((framework) => ({ value: framework.code, label: framework.name })) as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
        <div class="login-error-actions"><button class="btn preset-tonal" type="button" disabled={projectAdding} onclick={() => { projectAddDialog = null; }}>Отмена</button><button class="btn preset-filled-primary-500" type="submit" disabled={projectAdding || !projectAddDialog.location}>{projectAdding ? 'Добавляем…' : 'Добавить'}</button></div>
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
      <Dialog.Description class="login-error-description">Проект «{protectedAlert?.name}» защищен. Стирание файлов и баз данных запрещено.</Dialog.Description>
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
