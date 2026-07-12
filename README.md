# docker-cli

Консольная утилита для управления локальными Docker-окружениями.

## Команды

Перед первым запуском создайте конфигурацию:

```bash
bin/docker-cli init
```

Команда создаёт `~/.config/docker-cli/compose/system`, а также файлы `.env` и `compose.yaml`, если они отсутствуют. Уже существующие файлы не перезаписываются.

После проверки параметров в `.env` можно запускать и останавливать системное окружение:

```bash
bin/docker-cli start
bin/docker-cli stop
```

`start` выполняет `docker compose up -d` для проекта `docker-cli`, а `stop` выполняет `docker compose down --remove-orphans` и удаляет общую сеть `docker-cli`. Если `.env` или `compose.yaml` отсутствуют, команды завершаются понятной ошибкой и предлагают выполнить `docker-cli init`.

## Базовые сервисы

Системный compose-файл запускает:

- `dnsdock` для автоматических DNS-имён контейнеров;
- `traefik` для HTTPS-доступа и выпуска сертификатов через Cloudflare DNS challenge;
- `dockge` как веб-интерфейс мониторинга и управления compose-стеками.

По умолчанию `BASE_HOST=local.kubehut.top`, поэтому Dockge будет доступен по адресу:

```text
https://dockge.local.kubehut.top
```

Язык сообщений CLI задаётся параметром `APP_LOCALE` в сгенерированном `.env`. По умолчанию используется русский (`ru`).

## Настройка dnsdock на Linux

`dnsdock` публикует DNS на адресе `172.17.0.1:53/udp`, который доступен с хоста через стандартный Docker bridge. Чтобы Linux-система резолвила имена вида `*.local.kubehut.top` через dnsdock, добавьте для базового домена отдельный DNS-маршрут.

Для systemd-resolved:

```bash
sudo resolvectl dns docker0 172.17.0.1
sudo resolvectl domain docker0 '~local.kubehut.top'
```

Проверка:

```bash
resolvectl query dockge.local.kubehut.top
```

Если используется другой `BASE_HOST`, замените `local.kubehut.top` в командах выше на своё значение.

## Упаковка в PHAR

```bash
php -d phar.readonly=0 scripts/build-phar.php
```

Команда создаёт `build/docker-cli.phar`.
