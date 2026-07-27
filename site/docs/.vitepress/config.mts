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
    returnToTopLabel: 'Наверх',
    nav: [
      { text: 'Руководство', link: '/guide/getting-started' },
      { text: 'Команды', link: '/reference/commands' }
    ],
    sidebar: [
      {
        text: 'Руководство',
        items: [
          { text: 'Быстрый старт', link: '/guide/getting-started' },
          { text: 'Базовые сервисы', link: '/guide/services' },
          { text: 'Домен в Cloudflare', link: '/guide/cloudflare' },
          { text: 'DNS и браузеры', link: '/guide/dns' },
          { text: 'Xdebug', link: '/guide/xdebug' },
          { text: 'Задачи', link: '/guide/tasks' },
          { text: 'Образы', link: '/guide/images' },
          { text: 'PHAR', link: '/guide/phar' }
        ]
      },
      {
        text: 'Справочник',
        items: [
          { text: 'Команды', link: '/reference/commands' }
        ]
      }
    ]
  }
})
