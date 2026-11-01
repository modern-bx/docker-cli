<script>
  let { activeSection = 'projects' } = $props();
</script>

<nav class="tabs" aria-label="Разделы панели">
  <a class:active={activeSection === 'projects'} class="tab" href="#/projects" aria-current={activeSection === 'projects' ? 'page' : undefined}>Проекты</a>
  <a class:active={activeSection === 'logs'} class="tab" href="#/journal" aria-current={activeSection === 'logs' ? 'page' : undefined}>Журнал</a>
  <a class:active={activeSection === 'settings'} class="tab" href="#/settings/projects" aria-current={activeSection === 'settings' ? 'page' : undefined}>Настройки</a>
</nav>
