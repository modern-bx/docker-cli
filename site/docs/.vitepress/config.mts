import { defineConfig } from 'vitepress'

const base = process.env.VITEPRESS_BASE || '/'

export default defineConfig({
  base,
  title: 'modern-bx/docker-cli',
  description: 'Документация средства управления Docker-окружениями',
  lang: 'ru-RU',
  head: [
    [
      'script',
      { type: 'text/javascript' },
      `(function(m,e,t,r,i,k,a){
        m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
        m[i].l=1*new Date();
        for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
        k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)
      })(window, document,'script','https://mc.yandex.ru/metrika/tag.js?id=112831314', 'ym');

      ym(112831314, 'init', {ssr:true, webvisor:true, clickmap:true, ecommerce:"dataLayer", referrer: document.referrer, url: location.href, accurateTrackBounce:true, trackLinks:true});`
    ],
    [
      'noscript',
      {},
      '<div><img src="https://mc.yandex.ru/watch/112831314" style="position:absolute; left:-9999px;" alt="" /></div>'
    ]
  ],
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
          { text: 'ProxySQL и ProxyWeb', link: '/guide/proxy' },
          { text: 'DBTrail', link: '/guide/dbtrail' },
          { text: 'Домен в Cloudflare', link: '/guide/cloudflare' },
          { text: 'DNS и браузеры', link: '/guide/dns' },
          { text: 'Xdebug', link: '/guide/xdebug' },
          { text: 'PHP-SPX', link: '/guide/spx' },
          { text: 'Задачи', link: '/guide/tasks' },
          { text: 'Очереди', link: '/guide/queues' },
          { text: 'Бэкапы', link: '/guide/backups' },
          { text: 'Последние изменения', link: '/guide/recent-changes' },
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
