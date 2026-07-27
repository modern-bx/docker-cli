<script setup lang="ts">
import { onMounted, ref } from 'vue'

defineProps<{ compact?: boolean }>()

type Font = 'ubuntu' | 'noto'

const storageKey = 'preferred-font'
const selectedFont = ref<Font>('ubuntu')

function applyFont(font: Font) {
  selectedFont.value = font
  document.documentElement.dataset.font = font
  localStorage.setItem(storageKey, font)
}

onMounted(() => {
  const savedFont = localStorage.getItem(storageKey)
  applyFont(savedFont === 'noto' ? 'noto' : 'ubuntu')
})
</script>

<template>
  <label class="FontSwitcher" :class="{ compact }">
    <span class="label">Шрифт</span>
    <select
      :value="selectedFont"
      aria-label="Шрифт"
      @change="applyFont(($event.target as HTMLSelectElement).value as Font)"
    >
      <option value="ubuntu">Ubuntu Regular</option>
      <option value="noto">Noto Sans</option>
    </select>
  </label>
</template>

<style scoped>
.FontSwitcher {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-top: 12px;
  border-radius: 8px;
  padding: 12px 14px 12px 16px;
  background-color: var(--vp-c-bg-soft);
}

.label {
  line-height: 24px;
  font-size: 12px;
  font-weight: 500;
  color: var(--vp-c-text-2);
}

select {
  min-width: 142px;
  border: 1px solid var(--vp-c-divider);
  border-radius: 6px;
  padding: 4px 28px 4px 8px;
  background-color: var(--vp-c-bg);
  color: var(--vp-c-text-1);
  font: inherit;
  font-size: 12px;
  cursor: pointer;
}

.compact {
  display: none;
}

/*
 * VitePress moves its appearance control into the header's "more" menu below
 * 1280px. Keep the font control visible throughout the desktop/tablet header
 * range instead of tying it to the much wider appearance-toggle breakpoint.
 */
@media (min-width: 768px) {
  .compact {
    display: flex;
    margin: 0 0 0 12px;
    padding: 0;
    background: transparent;
  }

  .compact .label {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
  }
}
</style>
