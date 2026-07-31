# docker-cli

`docker-cli` — консольная утилита для управления локальными Docker-окружениями и регистрации PHP-проектов в общем системном compose-стеке.

## Что внутри

- Инициализация системной конфигурации в `~/.config/docker-cli/compose/system`.
- Seed секретов и параметров окружения.
- Регистрация Laravel, Symfony, Bitrix и Bitrix24 проектов.
- Системный compose-стек с Traefik, dnsdock, Dockhand, MySQL, PostgreSQL, Adminer, Mailpit и OpenResty.
- Сборка и публикация кастомных образов.
- Настройка Xdebug с отдельным IDE-портом на каждый проект.

## Быстрый старт

```bash
bin/docker-cli config:init
bin/docker-cli config:seed
bin/docker-cli system:start
```

Перед запуском укажите свой домен в `BASE_HOST` и настройте Cloudflare по инструкции [Настройка домена в Cloudflare](./guide/cloudflare.md).

После запуска Dockhand будет доступен по адресу `https://dockhand.<ваш-домен>`, а Adminer — по адресу `https://adminer.<ваш-домен>`.

Дальше перейдите к разделу [Быстрый старт](./guide/getting-started.md) или откройте [справочник команд](./reference/commands.md).
