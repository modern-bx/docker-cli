<script>
  import { onMount } from 'svelte';
  import { Combobox, Dialog, useListCollection } from '@skeletonlabs/skeleton-svelte';
  import { ExternalLink, Play, Power, RotateCw, Save, Square, Trash2 } from '@lucide/svelte';
  import { getLogs, getProjects, getSystemStatus, runProjectAction, runSystemAction, saveProjectNotes } from './api.js';

  const TOKEN_KEY = 'docker-cli-panel-token';
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
  ];
  const logStatusCollection = useListCollection({ items: logStatuses });
  const pageSizeCollection = useListCollection({ items: [25, 50, 100].map((value) => ({ value: String(value), label: String(value) })) });
  let login = '';
  let password = '';
  let currentLogin = '';
  let token = '';
  let error = '';
  let errorStatus = 0;
  let errorTitle = 'Ошибка';
  let loading = true;
  let submitting = false;
  let profileOpen = false;
  let themeOpen = false;
  let theme = 'vox';
  let mode = 'system';
  let font = 'ubuntu';
  let systemDark = false;
  let projects = [];
  let selectedProjectName = '';
  let projectDetailTab = 'info';
  let activeSection = 'projects';
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
  let systemConfirmation = null;
  let projectConfirmation = null;
  let projectContextMenu = null;
  let notesProjectName = '';
  let noteTags = [];
  let noteTagInput = '';
  let noteDescription = '';
  let notesSaving = false;
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
      : queueItems.some((item) => item.status === '40-failure') ? 'failure' : 'healthy';

  $: selectedProject = projects.find((project) => project.name === selectedProjectName) || null;
  $: if (selectedProject && selectedProject.name !== notesProjectName) {
    notesProjectName = selectedProject.name;
    noteTags = [...selectedProject.tags];
    noteTagInput = '';
    noteDescription = selectedProject.description;
  }
  $: filteredProjects = projects.filter((project) => {
    const matchesName = project.name.toLocaleLowerCase().includes(projectQuery.trim().toLocaleLowerCase());
    const tags = [project.language || 'no-language', project.framework || 'no-framework', ...project.tags];
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

  function applyHashNavigation() {
    if (!token) return;
    const segments = window.location.hash.replace(/^#\/?/, '').split('/').filter(Boolean);
    if (segments[0] === 'logs') {
      window.location.hash = '#/journal';
      return;
    }
    if (segments[0] === 'journal') {
      activeSection = 'logs';
      selectedProjectName = '';
      loadLogs();
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
    const tab = ['notes', 'journal'].includes(segments[2]) ? segments[2] : 'info';
    selectedProjectName = projectName;
    projectDetailTab = tab;
    if (tab === 'journal') loadLogs();
    if (segments.length !== 3 || !['info', 'notes', 'journal'].includes(segments[2])) navigateToProject(projectName, tab);
  }

  async function loadLogs() {
    if (!token) return;
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
    } catch (cause) {
      projectsError = cause instanceof Error ? cause.message : 'Не удалось загрузить журнал.';
    } finally {
      if (requestId === logRequestId) logsLoading = false;
    }
  }

  function changeLogProject(value) {
    logProject = value || 'all';
    logPage = 1;
    loadLogs();
  }

  function changeLogStatus(value) {
    logStatus = value || 'all';
    logPage = 1;
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
    clearTimeout(logFilterTimer);
    logFilterTimer = setTimeout(loadLogs, 250);
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
    projectContextMenu = null;
    if (event.target instanceof Element && event.target.closest('.header-menu')) return;
    themeOpen = false;
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

  async function api(path, options = {}) {
    const response = await fetch(path, {
      ...options,
      headers: {
        'Content-Type': 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
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
    token = data.token;
    currentLogin = data.login;
    localStorage.setItem(TOKEN_KEY, token);
    if (resetNavigation || window.location.hash === '#/login') navigateToProject('', 'info');
    else applyHashNavigation();
    connectPanelChannel();
  }

  function logout() {
    disconnectPanelChannel();
    token = '';
    currentLogin = '';
    profileOpen = false;
    localStorage.removeItem(TOKEN_KEY);
    window.location.hash = '#/login';
  }

  async function checkSession() {
    if (!token || systemPending) return;
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
    projectsLoading = false;
    if (selectedProjectName && !projects.some((project) => project.name === selectedProjectName)) navigateToProject('', 'info');
  }

  function connectPanelChannel() {
    if (!panelChannelEnabled || !token || panelSocket?.readyState === WebSocket.OPEN || panelSocket?.readyState === WebSocket.CONNECTING) return;
    clearTimeout(panelReconnectTimer);
    if (projects.length === 0) projectsLoading = true;
    const protocol = location.protocol === 'https:' ? 'wss:' : 'ws:';
    const query = new URLSearchParams({ channel: PANEL_CHANNEL, token });
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
      if (panelChannelEnabled && token) panelReconnectTimer = setTimeout(connectPanelChannel, 1_000);
    };
  }

  function disconnectPanelChannel() {
    panelChannelEnabled = false;
    clearTimeout(panelReconnectTimer);
    panelSocket?.close();
    panelSocket = null;
  }

  function formatQueueDate(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString('ru-RU');
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

  async function projectAction(action, name) {
    systemPending = true;
    projectsError = '';
    systemPendingMessage = action === 'wipe'
      ? `Удаляем файлы проекта «${name}»…`
      : `${action === 'enable' ? 'Включаем' : 'Отключаем'} проект «${name}»…`;
    try {
      const data = await runProjectAction(api, name, action);
      projects = data.projects;
      if (action !== 'wipe' && !projectHasState(data.projects, action, name) && !(await waitForProjectAction(action, name))) {
        throw Object.assign(new Error('Не удалось дождаться подтверждения статуса проекта.'), { status: 504 });
      }
    } catch (cause) {
      let reconciled = false;
      if (action !== 'wipe' && !(cause instanceof Error && 'status' in cause)) {
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
    if (action === 'disable' || action === 'wipe') {
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
    token = localStorage.getItem(TOKEN_KEY) || '';
    if (!token) window.location.hash = '#/login';
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
  onkeydown={(event) => { if (event.key === 'Escape') { themeOpen = false; profileOpen = false; systemOpen = false; queueOpen = false; } }}
/>

<svelte:head><title>{token ? 'docker-cli' : 'Вход — docker-cli'}</title></svelte:head>

<div class:panel-shell={token && !loading} class="min-h-screen bg-surface-50-950 text-surface-950-50 flex flex-col">
  <header class="app-header h-16 border-b border-surface-200-800 bg-surface-100-900 flex items-center px-5 md:px-8 shadow-sm">
    {#if token}<a href="#/projects" class="font-bold text-xl no-underline">docker-cli</a>{/if}
    {#if token}
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
                  <span class="queue-item-code" title={item.code}>{item.code}</span>
                  {#if item.status !== '20-active'}
                    <button class="btn btn-sm preset-tonal" type="button" onclick={() => { queueOpen = false; queueConfirmation = item; }}><Trash2 size={14} aria-hidden="true" />Удалить</button>
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
      <div class="relative header-menu">
        <button class="btn-icon preset-tonal theme-trigger" type="button" aria-label="Настроить оформление" aria-haspopup="dialog" aria-expanded={themeOpen} onclick={() => { themeOpen = !themeOpen; profileOpen = false; }}>
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
      {#if token}
        <div class="relative header-menu">
          <button class="btn preset-tonal" type="button" aria-expanded={profileOpen} onclick={() => { profileOpen = !profileOpen; themeOpen = false; }}>{currentLogin}</button>
          {#if profileOpen}
            <div class="card preset-filled-surface-100-900 absolute right-0 mt-2 min-w-44 p-2 shadow-xl z-10">
              <button class="btn w-full justify-start hover:preset-tonal-error" type="button" onclick={logout}>Выйти</button>
            </div>
          {/if}
        </div>
      {/if}
    </div>
  </header>

  <main class:workspace={token && !loading} class="flex-1 flex items-center justify-center p-5">
    {#if loading}
      <div class="animate-pulse text-surface-500">Проверка сессии…</div>
    {:else if !token}
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
        </nav>
        {#if activeSection === 'projects'}
        <div class="projects-layout">
          <aside class="project-sidebar" aria-label="Список проектов">
            <div class="project-sidebar-title">
              <h1>Проекты</h1>
              <span>{projects.length}</span>
            </div>
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
                        <strong>{project.name}</strong>
                        <span class="project-tags">
                          {#each [project.language || 'no-language', project.framework || 'no-framework', ...project.tags] as tag}
                            <button type="button" onclick={(event) => { event.stopPropagation(); addProjectTag(tag); }}>{tag}</button>
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
                <a class:active={projectDetailTab === 'journal'} class="project-detail-tab" href={projectHash(selectedProject.name, 'journal')} aria-current={projectDetailTab === 'journal' ? 'page' : undefined}>Журнал</a>
              </nav>
              <div class="project-details-scroll">
                {#if projectDetailTab === 'info'}
                <section class="project-tab-content card preset-filled-surface-100-900" aria-label="Общее">
                  <dl class="project-fields">
                    <div><dt>Название</dt><dd>{selectedProject.name}</dd></div>
                    <div><dt>Язык</dt><dd>{selectedProject.language || 'Не указан'}</dd></div>
                    <div><dt>Фреймворк</dt><dd>{selectedProject.framework || 'Не указан'}</dd></div>
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
                        <Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly />{#if logStatus !== 'all'}<button class="log-filter-clear" type="button" aria-label="Сбросить статус" onclick={(event) => { event.stopPropagation(); changeLogStatus('all'); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control>
                        <Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each logStatuses as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner>
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
                        {:else}{#each logItems as item}<tr><td>{formatQueueDate(item.timestamp)}</td><td><button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('queueItem', item.queueItem)}>{formatLogValue(item.queueItem)}</button></td><td><button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('itemCode', item.itemCode)}>{formatLogValue(item.itemCode)}</button></td><td>{formatLogValue(item.queueCode)}</td><td>{#if item.status}<button class="log-filter-link" type="button" onclick={() => changeLogStatus(item.status)}>{logStatusLabel(item.status)}</button>{:else}—{/if}</td><td>{#if item.taskCode}<button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('taskCode', item.taskCode)}>{item.taskCode}</button>{:else}—{/if}</td><td>{formatLogValue(item.result)}</td><td>{formatLogValue(item.message)}</td></tr>{/each}{/if}
                      </tbody>
                    </table>
                  </div>
                  <footer class="log-pagination">
                    <span>{logTotal ? `${(logPage - 1) * logPageSize + 1}–${Math.min(logPage * logPageSize, logTotal)} из ${logTotal}` : '0 записей'}</span>
                    <div class="log-pagination-controls"><button class="btn btn-sm preset-tonal" type="button" disabled={logPage === 1 || logsLoading} onclick={() => changeLogPage(logPage - 1)}>Назад</button><button class="btn btn-sm preset-tonal" type="button" disabled={logPage >= Math.ceil(logTotal / logPageSize) || logsLoading} onclick={() => changeLogPage(logPage + 1)}>Вперёд</button><label><span>На странице</span><Combobox collection={pageSizeCollection} value={[String(logPageSize)]} openOnClick onValueChange={(details) => details.value[0] && changeLogPageSize(details.value[0])}><Combobox.Control class="page-size-control font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each [25, 50, 100] as value}<Combobox.Item item={{ value: String(value), label: String(value) }} class="font-combobox-item"><Combobox.ItemText>{value}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label></div>
                  </footer>
                </section>
                {/if}
              </div>
            {:else}
              <div class="select-project">Выберите проект</div>
            {/if}
          </div>
        </div>
        {:else}
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
                  <Combobox.Control class="font-combobox-control"><Combobox.Input class="font-combobox-input" readonly />{#if logStatus !== 'all'}<button class="log-filter-clear" type="button" aria-label="Сбросить статус" onclick={(event) => { event.stopPropagation(); changeLogStatus('all'); }}>×</button>{/if}<Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control>
                  <Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each logStatuses as item}<Combobox.Item {item} class="font-combobox-item"><Combobox.ItemText>{item.label}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner>
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
                  {:else}{#each logItems as item}<tr><td>{formatQueueDate(item.timestamp)}</td><td><button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('queueItem', item.queueItem)}>{formatLogValue(item.queueItem)}</button></td><td><button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('itemCode', item.itemCode)}>{formatLogValue(item.itemCode)}</button></td><td>{formatLogValue(item.project)}</td><td>{formatLogValue(item.queueCode)}</td><td>{#if item.status}<button class="log-filter-link" type="button" onclick={() => changeLogStatus(item.status)}>{logStatusLabel(item.status)}</button>{:else}—{/if}</td><td>{#if item.taskCode}<button class="log-filter-link" type="button" onclick={() => changeTextLogFilter('taskCode', item.taskCode)}>{item.taskCode}</button>{:else}—{/if}</td><td>{formatLogValue(item.result)}</td><td>{formatLogValue(item.message)}</td></tr>{/each}{/if}
                </tbody>
              </table>
            </div>
            <footer class="log-pagination">
              <span>{logTotal ? `${(logPage - 1) * logPageSize + 1}–${Math.min(logPage * logPageSize, logTotal)} из ${logTotal}` : '0 записей'}</span>
              <div class="log-pagination-controls">
                <button class="btn btn-sm preset-tonal" type="button" disabled={logPage === 1 || logsLoading} onclick={() => changeLogPage(logPage - 1)}>Назад</button>
                <button class="btn btn-sm preset-tonal" type="button" disabled={logPage >= Math.ceil(logTotal / logPageSize) || logsLoading} onclick={() => changeLogPage(logPage + 1)}>Вперёд</button>
                <label><span>На странице</span><Combobox collection={pageSizeCollection} value={[String(logPageSize)]} openOnClick onValueChange={(details) => details.value[0] && changeLogPageSize(details.value[0])}><Combobox.Control class="page-size-control font-combobox-control"><Combobox.Input class="font-combobox-input" readonly /><Combobox.Trigger class="font-combobox-trigger" /></Combobox.Control><Combobox.Positioner class="font-combobox-positioner"><Combobox.Content class="font-combobox-content card preset-filled-surface-100-900 shadow-xl">{#each [25, 50, 100] as value}<Combobox.Item item={{ value: String(value), label: String(value) }} class="font-combobox-item"><Combobox.ItemText>{value}</Combobox.ItemText><Combobox.ItemIndicator class="font-combobox-indicator" /></Combobox.Item>{/each}</Combobox.Content></Combobox.Positioner></Combobox></label>
              </div>
            </footer>
          </section>
        {/if}
        {#if projectsError}<p class="projects-error" role="status">{projectsError}</p>{/if}
      </section>
    {/if}
  </main>
</div>

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
    <button type="button" role="menuitem" onclick={() => runContextProjectAction(projectContextMenu.project.enabled ? 'disable' : 'enable')}>
      <Power size={16} aria-hidden="true" />{projectContextMenu.project.enabled ? 'Отключить' : 'Включить'}
    </button>
    <button class="danger" type="button" role="menuitem" onclick={() => runContextProjectAction('wipe')}>
      <Trash2 size={16} aria-hidden="true" />Стереть
    </button>
  </div>
{/if}

<Dialog open={Boolean(projectConfirmation)} onOpenChange={({ open }) => { if (!open) projectConfirmation = null; }}>
  <Dialog.Backdrop class="login-error-backdrop" />
  <Dialog.Positioner class="login-error-positioner">
    <Dialog.Content class={`login-error-dialog card preset-filled-surface-100-900 shadow-2xl${projectConfirmation?.action === 'wipe' ? ' error-alert' : ''}`}>
      <Dialog.Title class="login-error-title">{projectConfirmation?.action === 'wipe' ? 'Стереть проект?' : 'Отключить проект?'}</Dialog.Title>
      <Dialog.Description class="login-error-description">
        {#if projectConfirmation?.action === 'wipe'}
          Все файлы из директории проекта «{projectConfirmation?.project.name}», кроме служебной директории .docker-cli, будут безвозвратно удалены.
        {:else}
          Проект «{projectConfirmation?.project.name}» станет недоступен через веб-сервер. Его можно будет включить снова.
        {/if}
      </Dialog.Description>
      <div class="login-error-actions system-confirm-actions">
        <Dialog.CloseTrigger class="btn preset-tonal" type="button">Отмена</Dialog.CloseTrigger>
        <button class={`btn ${projectConfirmation?.action === 'wipe' ? 'preset-filled-error-500' : 'preset-filled-primary-500'}`} type="button" onclick={() => { const confirmation = projectConfirmation; projectConfirmation = null; if (confirmation) projectAction(confirmation.action, confirmation.project.name); }}>
          {projectConfirmation?.action === 'wipe' ? 'Стереть' : 'Отключить'}
        </button>
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
