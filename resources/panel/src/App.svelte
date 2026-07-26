<script>
  import { onMount } from 'svelte';

  const TOKEN_KEY = 'docker-cli-panel-token';
  const THEME_KEY = 'docker-cli-panel-theme';
  let login = '';
  let password = '';
  let currentLogin = '';
  let token = '';
  let error = '';
  let loading = true;
  let submitting = false;
  let profileOpen = false;
  let dark = false;

  function setTheme(value) {
    dark = value;
    document.documentElement.classList.toggle('dark', dark);
    localStorage.setItem(THEME_KEY, dark ? 'dark' : 'light');
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
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Не удалось выполнить запрос.');
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

  async function submit() {
    error = '';
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
    } finally {
      submitting = false;
    }
  }

  onMount(() => {
    const savedTheme = localStorage.getItem(THEME_KEY);
    setTheme(savedTheme ? savedTheme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches);
    token = localStorage.getItem(TOKEN_KEY) || '';
    if (!token) window.location.hash = '#/login';
    checkSession().finally(() => { loading = false; });
    const interval = setInterval(checkSession, 60_000);
    return () => clearInterval(interval);
  });
</script>

<svelte:head><title>{token ? 'docker-cli' : 'Вход — docker-cli'}</title></svelte:head>

<div class="min-h-screen bg-surface-50-950 text-surface-950-50 flex flex-col">
  <header class="h-16 border-b border-surface-200-800 bg-surface-100-900 flex items-center px-5 md:px-8 shadow-sm">
    {#if token}<a href="#/" class="font-bold text-xl no-underline">docker-cli</a>{/if}
    <div class="ml-auto flex items-center gap-3">
      <button class="btn-icon preset-tonal" type="button" aria-label={dark ? 'Включить светлую тему' : 'Включить тёмную тему'} onclick={() => setTheme(!dark)}>
        <span aria-hidden="true">{dark ? '☀️' : '🌙'}</span>
      </button>
      {#if token}
        <div class="relative">
          <button class="btn preset-tonal" type="button" aria-expanded={profileOpen} onclick={() => profileOpen = !profileOpen}>{currentLogin}</button>
          {#if profileOpen}
            <div class="card preset-filled-surface-100-900 absolute right-0 mt-2 min-w-44 p-2 shadow-xl z-10">
              <button class="btn w-full justify-start hover:preset-tonal-error" type="button" onclick={logout}>Выйти</button>
            </div>
          {/if}
        </div>
      {/if}
    </div>
  </header>

  <main class="flex-1 flex items-center justify-center p-5">
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
    {/if}
  </main>
</div>

{#if error}
  <div class="fixed inset-x-4 bottom-5 flex justify-center z-20" role="alert">
    <div class="card preset-filled-error-500 max-w-md p-4 shadow-2xl flex items-center gap-4">
      <span>{error}</span>
      <button class="btn-icon preset-tonal ml-auto" type="button" aria-label="Закрыть" onclick={() => error = ''}>×</button>
    </div>
  </div>
{/if}
