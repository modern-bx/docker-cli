import { defineConfig } from 'vitepress'

const base = process.env.VITEPRESS_BASE || '/'

export default defineConfig({
  base,
  title: 'modern-bx/docker-cli',
  description: 'Документация средства управления Docker-окружениями',
  lang: 'ru-RU',
  outDir: '../dist',
  vite: {
    server: {
      watch: {
        ignored: ['**/node_modules/**', '**/dist/**', '**/.vitepress/cache/**']
      }
    }
  },
  cleanUrls: true,
  themeConfig: {
    search: { provider: 'local' },
    outline: { level: [2, 3], label: 'На странице' },
    docFooter: { prev: 'Назад', next: 'Далее' },
    darkModeSwitchLabel: 'Тема',
    sidebarMenuLabel: 'Меню',
    returnToTopLabel: 'Наверх'
  }
})
