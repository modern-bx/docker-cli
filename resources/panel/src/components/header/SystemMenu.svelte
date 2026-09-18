<script>
  import { Download, ExternalLink, Play, RotateCw, Square } from '@lucide/svelte';

  let { open = false, status = 'stopped', services = [], hasStoppedServices = false,
    hasRunningServices = false, updatePending = false, onToggleOpen, onRequestAction,
    onEnqueueUpdate, serviceUrl } = $props();
</script>

<div class="system-main-control">
  <button class="btn preset-tonal system-trigger" type="button" aria-expanded={open} onclick={onToggleOpen}>
    <span class={`system-dot ${status}`} aria-hidden="true"></span><span>Система</span>
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5" /></svg>
  </button>
  {#if open}
    <div class="system-menu card preset-filled-surface-100-900 shadow-2xl">
      <div class="system-menu-global-actions">
        {#if hasStoppedServices}<button class="btn btn-sm preset-tonal" type="button" onclick={() => onRequestAction('start')}><Play size={14} aria-hidden="true" />Запустить</button>{/if}
        {#if hasRunningServices}<button class="btn btn-sm preset-tonal" type="button" onclick={() => onRequestAction('stop')}><Square size={14} aria-hidden="true" />Остановить</button>{/if}
        <button class="btn btn-sm preset-tonal" type="button" onclick={() => onRequestAction('restart')}><RotateCw size={14} aria-hidden="true" />Перезапустить</button>
      </div>
      <div class="system-menu-divider" aria-hidden="true"></div>
      <div class="system-menu-global-actions">
        <button class="btn btn-sm preset-tonal" type="button" disabled={updatePending} onclick={onEnqueueUpdate}><Download size={14} aria-hidden="true" />{updatePending ? 'Добавляем…' : 'Обновить'}</button>
      </div>
      <div class="system-menu-divider" aria-hidden="true"></div>
      {#if services.length === 0}<p class="system-empty">Сервисы не найдены</p>{/if}
      {#each services as service (service.name)}
        {@const url = serviceUrl(service)}
        <div class="system-service">
          <span class={`system-dot ${service.running ? 'running' : 'stopped'}`} aria-hidden="true"></span>
          {#if url}
            <a class="system-service-name system-service-link" href={url} target="_blank" rel="noopener noreferrer" title={service.image}>{service.name}<ExternalLink size={13} aria-hidden="true" /></a>
          {:else}
            <span class="system-service-name" title={service.image}>{service.name}</span>
          {/if}
          <div class="system-actions">
            <button class="btn btn-sm preset-tonal" type="button" onclick={() => onRequestAction(service.running ? 'stop' : 'start', service.name)}>
              {#if service.running}<Square size={14} aria-hidden="true" />{:else}<Play size={14} aria-hidden="true" />{/if}
              {service.running ? 'Остановить' : 'Запустить'}
            </button>
            <button class="btn btn-sm preset-tonal" type="button" onclick={() => onRequestAction('restart', service.name)}><RotateCw size={14} aria-hidden="true" />Перезапустить</button>
          </div>
        </div>
      {/each}
    </div>
  {/if}
</div>
