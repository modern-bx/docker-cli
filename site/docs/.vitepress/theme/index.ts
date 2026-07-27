import DefaultTheme from 'vitepress/theme'
import type { Theme } from 'vitepress'
import { h } from 'vue'
import FontSwitcher from './FontSwitcher.vue'
import './styles.css'

export default {
  extends: DefaultTheme,
  Layout: () => h(DefaultTheme.Layout, null, {
    'nav-bar-content-after': () => h(FontSwitcher, { compact: true }),
    'nav-screen-content-after': () => h(FontSwitcher)
  })
} satisfies Theme
