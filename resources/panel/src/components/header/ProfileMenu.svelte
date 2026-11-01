<script>
  let { open = false, login = '', onToggleOpen, onLogout } = $props();
</script>

<div class="relative header-menu">
  <button class="btn preset-tonal" type="button" aria-expanded={open} onclick={onToggleOpen}>{login}</button>
  {#if open}
    <div class="card preset-filled-surface-100-900 absolute right-0 mt-2 min-w-44 p-2 shadow-xl z-10">
      <button class="btn w-full justify-start hover:preset-tonal-error" type="button" onclick={onLogout}>Выйти</button>
    </div>
  {/if}
</div>
