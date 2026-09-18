<script>
  import { Archive, Play, Square, Trash2 } from '@lucide/svelte';

  let { open = false, status = 'stopped', items = [], paused = false, pending = false, onToggleOpen,
    onToggleQueue, onOpenJournal, onArchive, onRequestDelete, formatDate } = $props();
</script>

<div class="queue-main-control">
  <button class="btn preset-tonal system-trigger" type="button" aria-expanded={open} onclick={onToggleOpen}>
    <span class={`queue-summary-dot ${status}`} aria-hidden="true"></span><span>Очередь</span>
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5" /></svg>
  </button>
  {#if open}
    <div class="queue-menu card preset-filled-surface-100-900 shadow-2xl">
      <div class="queue-menu-actions">
        <button class="btn btn-sm preset-tonal" type="button" disabled={pending} onclick={onToggleQueue}>
          {#if paused}<Play size={14} aria-hidden="true" />{:else}<Square size={14} aria-hidden="true" />{/if}
          {pending ? 'Подождите…' : paused ? 'Возобновить' : 'Приостановить'}
        </button>
      </div>
      <div class="system-menu-divider" aria-hidden="true"></div>
      {#if items.length === 0}<p class="system-empty">Очередь пуста</p>{/if}
      {#each items as item (item.file)}
        <div class="queue-item">
          <span class={`queue-dot status-${item.status}`} aria-hidden="true"></span>
          <time datetime={item.queuedAt}>{formatDate(item.queuedAt)}</time>
          <button class="queue-item-code queue-item-link" type="button" title={`Открыть журнал элемента ${item.code}`} onclick={() => onOpenJournal(item)}>{item.code}</button>
          {#if item.status !== '20-active'}
            <div class="queue-item-actions">
              <button class="btn btn-sm preset-tonal" type="button" onclick={() => onArchive(item)}><Archive size={14} aria-hidden="true" />Архивировать</button>
              <button class="btn-icon preset-tonal" type="button" aria-label="Удалить элемент очереди" title="Удалить" onclick={() => onRequestDelete(item)}><Trash2 size={14} aria-hidden="true" /></button>
            </div>
          {/if}
        </div>
      {/each}
    </div>
  {/if}
</div>
