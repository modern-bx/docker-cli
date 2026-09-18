# docker-cli

Консольная утилита для управления локальными Docker-окружениями.

## Документация

Полная документация перенесена в VitePress-сайт в [`site/docs`](site/docs):

- [быстрый старт](site/docs/guide/getting-started.md);
- [справочник команд](site/docs/reference/commands.md);
- [системные сервисы](site/docs/guide/services.md);
- [настройка домена в Cloudflare](site/docs/guide/cloudflare.md);
- [настройка DNS и браузеров](site/docs/guide/dns.md);
- [Xdebug](site/docs/guide/xdebug.md);
- [пользовательские задачи](site/docs/guide/tasks.md);
- [сборка и публикация образов](site/docs/guide/images.md);
- [упаковка в PHAR](site/docs/guide/phar.md).

## Быстрый старт

```bash
bin/docker-cli config:init
bin/docker-cli config:seed
bin/docker-cli system:start
```

Проект Laravel, Symfony, Bitrix или Bitrix24 можно зарегистрировать из корня проекта или любой вложенной директории:

```bash
bin/docker-cli project:up [my-project]
```

Удаление регистрации текущего проекта:

```bash
bin/docker-cli project:down
```

Остановка окружения:

```bash
bin/docker-cli system:stop
```

## Документация сайта

VitePress-конфиг находится в `site/docs/.vitepress`, исходники страниц — в `site/docs`, production-сборка выходит в `site/dist`.

```bash
cd site
npm run dev
npm run build
npm run preview
```

## Разработка

Для установки PHP-зависимостей и запуска проверок требуется PHP 8.2 или новее.
Версия платформы Composer зафиксирована на PHP 8.2, поэтому lock-файл остаётся
совместимым с минимально поддерживаемой версией.

После установки PHP-зависимостей полную локальную проверку изменений запускает
единая команда:

```bash
composer install
composer quality
```

`composer quality` проверяет синтаксис PHP-файлов и запускает автотесты. Отдельно тесты
можно запустить через `composer test`.
