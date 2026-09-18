<script>
  import { Bell, Trash2 } from '@lucide/svelte';

  let { open = false, notifications = [], badgeLevel = '', onToggleOpen, onArchiveAll, onArchive,
    formatDate, renderMarkdown } = $props();
</script>

<div class="relative header-menu">
  <button class="btn-icon preset-tonal notification-trigger" type="button" aria-label="Уведомления" aria-haspopup="dialog" aria-expanded={open} onclick={onToggleOpen}>
    <Bell size={19} aria-hidden="true" />
    {#if notifications.length > 0}<span class={`notification-badge ${badgeLevel}`}>{notifications.length}</span>{/if}
  </button>
  {#if open}
    <div class="notification-menu card preset-filled-surface-100-900 absolute right-0 mt-2 shadow-2xl z-20" role="dialog" aria-label="Уведомления">
      <div class="notification-menu-actions">
        <button class="btn btn-sm preset-tonal" type="button" disabled={notifications.length === 0} onclick={onArchiveAll}>Очистить</button>
      </div>
      <div class="system-menu-divider" aria-hidden="true"></div>
      {#if notifications.length === 0}<p class="notification-empty">Уведомлений нет</p>{/if}
      {#each notifications as notification (notification.file)}
        <article class="notification-item">
          <div>
            <time datetime={notification.time}>{formatDate(notification.time)}</time>
            <div class="notification-message">{@html renderMarkdown(notification.message)}</div>
          </div>
          <button class="btn-icon preset-tonal notification-delete" type="button" aria-label="Удалить уведомление" title="Удалить" onclick={() => onArchive(notification)}><Trash2 size={16} aria-hidden="true" /></button>
        </article>
      {/each}
    </div>
  {/if}
</div>
