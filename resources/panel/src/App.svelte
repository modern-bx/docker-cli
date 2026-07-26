<script>
  import { onMount } from 'svelte';
  import { Collapsible, Dialog } from '@skeletonlabs/skeleton-svelte';
  import { Play, Power, RotateCw, Square, Trash2 } from '@lucide/svelte';
  import { getProjects, getSystemStatus, runProjectAction, runSystemAction } from './api.js';

  const TOKEN_KEY = 'docker-cli-panel-token';
  const THEME_KEY = 'docker-cli-panel-color-theme';
  const MODE_KEY = 'docker-cli-panel-theme';
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
  let systemDark = false;
  let projects = [];
  let selectedProjectName = '';
  let projectsLoading = false;
  let projectsError = '';
  let projectQuery = '';
  let projectTags = [];
  let systemOpen = false;
  let systemStatus = 'stopped';
  let systemServices = [];
  let systemPending = false;
  let systemPendingMessage = '';
  let systemConfirmation = null;
  let projectConfirmation = null;
  const panelServices = ['dnsdock', 'panel-gateway', 'traefik'];

  $: hasRunningServices = systemServices.some((service) => service.running);
  $: hasStoppedServices = systemServices.some((service) => !service.running);

  $: selectedProject = projects.find((project) => project.name === selectedProjectName) || null;
  $: filteredProjects = projects.filter((project) => {
    const matchesName = project.name.toLocaleLowerCase().includes(projectQuery.trim().toLocaleLowerCase());
    const tags = [project.language || 'no-language', project.framework || 'no-framework'];
    return matchesName && projectTags.every((tag) => tags.includes(tag));
  });

  function addProjectTag(tag) {
    if (!projectTags.includes(tag)) projectTags = [...projectTags, tag];
  }

  function removeProjectTag(tag) {
    projectTags = projectTags.filter((item) => item !== tag);
  }

  function applyAppearance() {
    document.documentElement.dataset.theme = theme;
    document.documentElement.classList.toggle('dark', mode === 'dark' || (mode === 'system' && systemDark));
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
    if (event.target instanceof Element && event.target.closest('.header-menu')) return;
    themeOpen = false;
    profileOpen = false;
    systemOpen = false;
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

  function acceptSession(data) {
    token = data.token;
    currentLogin = data.login;
    localStorage.setItem(TOKEN_KEY, token);
    window.location.hash = '#/';
  }

  function logout() {
    token = '';
    currentLogin = '';
    profileOpen = false;
    localStorage.removeItem(TOKEN_KEY);
    window.location.hash = '#/login';
  }

  async function checkSession() {
    if (!token) return;
    try {
      acceptSession(await api('/api/auth/session'));
    } catch {
      logout();
    }
  }

  async function refreshProjects() {
    if (!token || systemPending) return;
    if (projects.length === 0) projectsLoading = true;
    try {
      const data = await getProjects(api);
      projects = data.projects;
      projectsError = '';
      if (selectedProjectName && !projects.some((project) => project.name === selectedProjectName)) {
        selectedProjectName = '';
      }
    } catch (cause) {
      // A service can accept Docker's "running" state before its HTTP
      // endpoint is ready. Do not turn these short transport gaps into toasts.
      if (cause instanceof Error && 'status' in cause && typeof cause.status === 'number' && cause.status < 500) {
        projectsError = cause.message;
      }
    } finally {
      projectsLoading = false;
    }
  }

  async function refreshSystem() {
    if (!token || systemPending) return;
    try {
      const data = await getSystemStatus(api);
      systemStatus = data.status;
      systemServices = data.services;
    } catch {
      systemStatus = 'stopped';
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
      refreshProjects();
      refreshSystem();
    }
  }

  async function waitForProjectAction(action, name) {
    for (let attempt = 0; attempt < 60; attempt += 1) {
      try {
        const data = await getProjects(api);
        projects = data.projects;
        const project = data.projects.find((item) => item.name === name);
        if (action === 'enable' ? project?.enabled === true : project?.enabled === false) return true;
      } catch {
        // Traefik can be briefly unavailable while project routing is rebuilt.
      }
      await new Promise((resolve) => setTimeout(resolve, 1_000));
    }
    return false;
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
      refreshSystem();
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
      acceptSession(data);
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
    applyAppearance();
    const updateSystemMode = (event) => {
      systemDark = event.matches;
      if (mode === 'system') applyAppearance();
    };
    media.addEventListener('change', updateSystemMode);
    token = localStorage.getItem(TOKEN_KEY) || '';
    if (!token) window.location.hash = '#/login';
    checkSession().finally(() => { loading = false; });
    const interval = setInterval(checkSession, 60_000);
    refreshProjects();
    const projectsInterval = setInterval(refreshProjects, 1_000);
    refreshSystem();
    const systemInterval = setInterval(refreshSystem, 1_000);
    return () => {
      clearInterval(interval);
      clearInterval(projectsInterval);
      clearInterval(systemInterval);
      media.removeEventListener('change', updateSystemMode);
    };
  });
</script>

<svelte:window
  onclick={closeMenus}
  onkeydown={(event) => { if (event.key === 'Escape') { themeOpen = false; profileOpen = false; systemOpen = false; } }}
/>

<svelte:head><title>{token ? 'docker-cli' : 'Вход — docker-cli'}</title></svelte:head>

<div class="min-h-screen bg-surface-50-950 text-surface-950-50 flex flex-col">
  <header class="app-header h-16 border-b border-surface-200-800 bg-surface-100-900 flex items-center px-5 md:px-8 shadow-sm">
    {#if token}<a href="#/" class="font-bold text-xl no-underline">docker-cli</a>{/if}
    {#if token}
      <div class="system-header header-menu">
        <div class="system-main-control">
          <button class="btn preset-tonal system-trigger" type="button" aria-expanded={systemOpen} onclick={() => { systemOpen = !systemOpen; themeOpen = false; profileOpen = false; }}>
            <span class={`system-dot ${systemStatus}`} aria-hidden="true"></span><span>Система</span>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5" /></svg>
          </button>
          {#if systemOpen}
            <div class="system-menu card preset-filled-surface-100-900 shadow-2xl">
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
        <div class="system-actions system-global-actions">
          {#if hasStoppedServices}<button class="btn btn-sm preset-tonal" type="button" onclick={() => requestSystemAction('start')}><Play size={14} aria-hidden="true" />Запустить</button>{/if}
          {#if hasRunningServices}<button class="btn btn-sm preset-tonal" type="button" onclick={() => requestSystemAction('stop')}><Square size={14} aria-hidden="true" />Остановить</button>{/if}
          <button class="btn btn-sm preset-tonal" type="button" onclick={() => requestSystemAction('restart')}><RotateCw size={14} aria-hidden="true" />Перезапустить</button>
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
          <button class="tab active" type="button" aria-current="page">Проекты</button>
        </nav>
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
              <input type="search" bind:value={projectQuery} placeholder={projectTags.length ? 'Название…' : 'Поиск по названию…'} />
            </label>
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
                    onclick={() => { selectedProjectName = project.name; }}
                    onkeydown={(event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); selectedProjectName = project.name; } }}
                  >
                    <span class:enabled={project.enabled} class="status-dot" title={project.enabled ? 'Включен' : 'Выключен'}></span>
                    <span class="project-summary">
                      <strong>{project.name}</strong>
                      <span class="project-tags">
                        {#each [project.language || 'no-language', project.framework || 'no-framework'] as tag}
                          <button type="button" onclick={(event) => { event.stopPropagation(); addProjectTag(tag); }}>{tag}</button>
                        {/each}
                      </span>
                    </span>
                  </div>
                {/each}
              </div>
            {/if}
          </aside>
          <div class="project-details">
            {#if selectedProject}
              <Collapsible defaultOpen={true} class="collapsible card preset-filled-surface-100-900">
                <Collapsible.Trigger class="collapsible-trigger">
                  <span>Общее</span>
                  <Collapsible.Indicator class="collapsible-indicator">⌄</Collapsible.Indicator>
                </Collapsible.Trigger>
                <Collapsible.Content class="collapsible-content">
                  <dl class="project-fields">
                    <div><dt>Название</dt><dd>{selectedProject.name}</dd></div>
                    <div><dt>Язык</dt><dd>{selectedProject.language || 'Не указан'}</dd></div>
                    <div><dt>Фреймворк</dt><dd>{selectedProject.framework || 'Не указан'}</dd></div>
                    <div><dt>Статус</dt><dd class:enabled={selectedProject.enabled} class="status-value"><i></i>{selectedProject.enabled ? 'Включен' : 'Выключен'}</dd></div>
                  </dl>
                  <div class="project-general-actions">
                    <button class="btn preset-tonal" type="button" onclick={() => requestProjectAction(selectedProject.enabled ? 'disable' : 'enable', selectedProject)}>
                      <Power size={16} aria-hidden="true" />{selectedProject.enabled ? 'Отключить' : 'Включить'}
                    </button>
                    <button class="btn preset-filled-error-500" type="button" onclick={() => requestProjectAction('wipe', selectedProject)}>
                      <Trash2 size={16} aria-hidden="true" />Стереть
                    </button>
                  </div>
                </Collapsible.Content>
              </Collapsible>
            {:else}
              <div class="select-project">Выберите проект</div>
            {/if}
          </div>
        </div>
        {#if projectsError}<p class="projects-error" role="status">{projectsError}</p>{/if}
      </section>
    {/if}
  </main>
</div>

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
