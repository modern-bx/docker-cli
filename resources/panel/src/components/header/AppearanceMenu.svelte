<script>
  import { Combobox } from '@skeletonlabs/skeleton-svelte';

  let { open = false, themes = [], theme = '', modes = [], mode = '', fonts = [], font = '',
    fontCollection, onToggleOpen, onSetTheme, onSetMode, onSetFont } = $props();
</script>

<div class="relative header-menu">
  <button class="btn-icon preset-tonal theme-trigger" type="button" aria-label="Настроить оформление" aria-haspopup="dialog" aria-expanded={open} onclick={onToggleOpen}>
    <span class="theme-dot" aria-hidden="true"></span>
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5" /></svg>
  </button>
  {#if open}
    <div class="theme-menu card preset-filled-surface-100-900 absolute right-0 mt-2 p-4 shadow-2xl z-20" role="dialog" aria-label="Настройки оформления">
      <div class="flex items-center justify-between mb-3">
        <strong>Тема</strong>
        <span class="text-sm text-surface-500">{themes.find(([value]) => value === theme)?.[1]}</span>
      </div>
      <div class="theme-grid" role="list" aria-label="Цветовая тема">
        {#each themes as [value, label]}
          <button class:active={theme === value} class="theme-option" type="button" data-theme={value} aria-label={label} aria-pressed={theme === value} title={label} onclick={() => onSetTheme(value)}>
            <span class="swatch"><i></i><i></i><i></i></span>
            <span>{label}</span>
          </button>
        {/each}
      </div>
      <div class="mode-switch mt-4" aria-label="Цветовой режим">
        {#each modes as [value, label]}
          <button class:active={mode === value} type="button" aria-pressed={mode === value} onclick={() => onSetMode(value)}>
            <span aria-hidden="true">{value === 'light' ? '☀' : value === 'dark' ? '☾' : '◐'}</span>
            {label}
          </button>
        {/each}
      </div>
      <div class="font-switch mt-4">
        <span id="font-switch-label">Шрифт</span>
        <Combobox collection={fontCollection} value={[font]} openOnClick onValueChange={(details) => details.value[0] && onSetFont(details.value[0])}>
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
