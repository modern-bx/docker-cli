<script>
  let { login = $bindable(''), password = $bindable(''), submitting = false, onSubmit } = $props();
</script>

<section class="card preset-filled-surface-100-900 w-full max-w-md p-7 md:p-9 shadow-xl" aria-labelledby="login-title">
  <h1 id="login-title" class="h2 text-center mb-2">Вход в панель</h1>
  <p class="text-center text-surface-500 mb-8">Введите данные пользователя docker-cli</p>
  <form class="space-y-5" onsubmit={(event) => { event.preventDefault(); onSubmit(); }}>
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
