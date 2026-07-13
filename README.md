# docker-cli

Консольная утилита для управления локальными Docker-окружениями.

## Команды

Перед первым запуском создайте конфигурацию:

```bash
bin/docker-cli init
```

Команда создаёт `~/.config/docker-cli/compose/system`, а также файлы `.env` и `compose.yaml`, если они отсутствуют. Уже существующие файлы не перезаписываются. Опция `--update` после интерактивного подтверждения перезаписывает статические файлы конфигурации из шаблонов; сейчас это `compose.yaml`. Опция `--migrate` добавляет в редактируемые файлы отсутствующие параметры из шаблонов; сейчас это `.env`. Опции `--update` и `--migrate` можно указывать одновременно.

После проверки параметров в `.env` заполните генерируемые секреты:

```bash
bin/docker-cli seed
```

Команда спросит подтверждение и заполнит только пустые сидируемые значения в `.env`. Сейчас она заполняет `DOCKGE_ADMIN_USERNAME=admin`, случайный `DOCKGE_ADMIN_PASSWORD`, а также случайные пароли `MYSQL_ROOT_PASSWORD`, `MYSQL_PASSWORD` и `POSTGRES_PASSWORD`. Уже заполненные значения не меняются. Имена баз и пользователей MySQL/PostgreSQL предзаполнены в `.env` значением `system`, их можно поменять вручную до первого запуска. Для автоматических сценариев можно использовать `bin/docker-cli seed --yes`.

Проект Laravel, Symfony, Bitrix или Bitrix24 можно поднять и зарегистрировать из корня проекта или любой вложенной директории:

```bash
bin/docker-cli up [my-project]
```

Команда определяет фреймворк, создает запись `~/.config/docker-cli/projects/my-project/project.yaml` с именем проекта, кодовым именем фреймворка, корнем проекта и document root, а также записывает метаданные проекта в `.docker-cli.yaml` в корне найденного проекта. После изменения регистрации пересобираются проектные хосты OpenResty в `~/.config/docker-cli/compose/system/config/openresty/hosts`, обновляется переменная `PROJECT_WEB_DNSDOCK_ALIAS` в системном `.env` для DNS-алиасов проектных доменов на Traefik и пересоздается пул общих проектных сервисов. Если имя проекта не указано, используется название папки проекта. Если имя не соответствует конвенции, проект с таким именем уже зарегистрирован или фреймворк не определен, команда завершается ошибкой. Для пропуска перезапуска используйте `--no-restart`.

Чтобы удалить регистрацию текущего проекта, выполните команду из корня проекта или любой вложенной директории:

```bash
bin/docker-cli down
```

Команда читает имя проекта из `.docker-cli.yaml`, удаляет соответствующую директорию в `~/.config/docker-cli/projects`, удаляет `.docker-cli.yaml`, пересобирает проектные хосты OpenResty, обновляет `PROJECT_WEB_DNSDOCK_ALIAS` и пересоздает пул общих проектных сервисов. Если фреймворк не определен или файл метаданных отсутствует, команда завершается ошибкой. Для пропуска перезапуска используйте `--no-restart`.

После этого можно запускать и останавливать системное окружение:

```bash
bin/docker-cli start
bin/docker-cli stop
```

`start` выполняет `docker compose up -d` для проекта `docker-cli`, а `stop` выполняет `docker compose down --remove-orphans` и удаляет общую сеть `docker-cli`. Если `.env` или `compose.yaml` отсутствуют, команды завершаются понятной ошибкой и предлагают выполнить `docker-cli init`.

## Базовые сервисы

Системный compose-файл запускает:

- `dnsdock` для автоматических DNS-имён контейнеров;
- `traefik` для HTTPS-доступа и выпуска сертификатов через Cloudflare DNS challenge;
- `dockge` как веб-интерфейс мониторинга и управления compose-стеками;
- `dockge-seed` как одноразовый контейнер, который при первом запуске создаёт администратора Dockge через setup-событие Socket.IO и спокойно завершается, если Dockge уже инициализирован;
- `mysql` на образе `mysql:8.0`;
- `postgres` на образе `postgres:18`, актуальной стабильной ветке PostgreSQL на 12 июля 2026 года;
- `adminer` как HTTPS web-интерфейс для работы с базами данных;
- `openresty` для отдачи статики зарегистрированных проектов через проектные хосты вида `web-<project-name>.${BASE_HOST}`.

Dockge не предоставляет штатные переменные окружения для bootstrap-администратора: в upstream это обсуждалось как feature request. Поэтому bootstrap реализован отдельным одноразовым seed-контейнером, а логин/пароль берутся из `.env`.

MySQL и PostgreSQL не публикуют порты на хост: они доступны только из сети `docker-cli` по стандартным портам `3306` и `5432` и коротким алиасам `mysql` / `postgres` либо DNS-именам `mysql.${BASE_HOST}` / `postgres.${BASE_HOST}`. Adminer публикуется только через Traefik с TLS.

OpenResty запускает nginx worker от root, потому что проектные файлы монтируются read-only из контейнера, а локальные директории проектов часто лежат внутри домашней директории с правами, недоступными пользователю `nobody` внутри контейнера. Для проектов в `/home` путь монтируется в контейнер без префикса, чтобы абсолютные пути и symlink-и внутри проекта резолвились так же, как на хосте; остальные пути остаются доступны через fallback-mount `/host`.

Файлы, генерируемые системными контейнерами баз данных, лежат рядом с системным compose-файлом в `~/.config/docker-cli/compose/system/data`: для MySQL используются `data/mysql/data` и `data/mysql/logs`, для PostgreSQL — `data/postgres/data` и `data/postgres/logs`.

По умолчанию `BASE_HOST=local.kubehut.top`, поэтому Dockge будет доступен по адресу:

```text
https://dockge.local.kubehut.top
```

DNS-имена Adminer и проектные имена вида `web-<project-name>.${BASE_HOST}` регистрируются на контейнер Traefik через `DNSDOCK_ALIAS`, поэтому браузер попадает в HTTPS-router Traefik, а не напрямую в контейнер приложения. Для `websecure` и router-а OpenResty явно указан wildcard-домен `*.${BASE_HOST}` через Cloudflare DNS challenge, чтобы regex-router проектных доменов получал LE-сертификат, а не дефолтный самоподписанный сертификат Traefik. ACME DNS challenge использует публичные резолверы `1.1.1.1` и `8.8.8.8`, чтобы Traefik не определял локальную зону dnsdock как Cloudflare-зону.

Adminer доступен по адресу:

```text
https://adminer.local.kubehut.top
```

Язык сообщений CLI задаётся параметром `APP_LOCALE` в сгенерированном `.env`. По умолчанию используется русский (`ru`). Логин администратора Dockge по умолчанию — `admin`, пароль записывает команда `seed`.

## Настройка dnsdock на Linux

`dnsdock` публикует DNS на адресе `172.17.0.1:53/udp`, который доступен с хоста через стандартный Docker bridge. Чтобы Linux-система резолвила имена вида `*.local.kubehut.top` через dnsdock, добавьте для базового домена отдельный DNS-маршрут.

Ниже описан способ через drop-in для `systemd-resolved`. Он проверялся только на Ubuntu 24.04.

Создайте drop-in:

```bash
sudo mkdir -p /etc/systemd/resolved.conf.d
sudo tee /etc/systemd/resolved.conf.d/docker-cli.conf >/dev/null <<'EOF'
[Resolve]
DNS=172.17.0.1
Domains=~local.kubehut.top
EOF
```

Перезапустите `systemd-resolved` и сбросьте кеш:

```bash
sudo systemctl restart systemd-resolved
sudo resolvectl flush-caches
```

Проверка системного резолва:

```bash
resolvectl status
resolvectl query dockge.local.kubehut.top
```

Ожидаемо `resolvectl query dockge.local.kubehut.top` должен вернуть IP контейнера `traefik`, а не публичный wildcard-адрес из Cloudflare. Для прямой проверки dnsdock используйте:

```bash
dig @172.17.0.1 dockge.local.kubehut.top
```

Если используется другой `BASE_HOST`, замените `local.kubehut.top` в drop-in и командах проверки на своё значение.

## Проверка браузеров

### Firefox

Если `resolvectl query dockge.local.kubehut.top` возвращает IP Traefik, но Firefox открывает другой хост или публичный IP, проверьте:

- расширения VPN/proxy, например FoxyProxy, SwitchyOmega, корпоративные VPN-расширения и похожие инструменты;
- настройки `Settings → General → Network Settings`: для диагностики выберите `No proxy`;
- `about:config`: `network.proxy.type` должен быть `0` для режима `No proxy`;
- `about:config`: `network.proxy.socks_remote_dns` временно поставьте в `false`, если используется SOCKS;
- `about:config`: `network.trr.mode = 5` отключает Firefox DNS-over-HTTPS/TRR;
- `about:config`: в `network.trr.excluded-domains` можно добавить `local.kubehut.top`;
- `about:networking#dns`: нажмите `Clear DNS Cache` после изменения настроек.

Если Firefox установлен как snap, сравните резолв внутри и снаружи snap:

```bash
getent hosts dockge.local.kubehut.top
snap run --shell firefox
getent hosts dockge.local.kubehut.top
cat /etc/resolv.conf
```

Если внутри snap возвращается другой IP, проблема в DNS-окружении snap. Для диагностики можно проверить обычную deb/tarball-сборку Firefox с чистым профилем.

### Chrome / Chromium

Если Chrome или Chromium открывает не Traefik, проверьте:

- расширения VPN/proxy и системные VPN-клиенты;
- `Settings → System → Open your computer's proxy settings`: убедитесь, что браузер не уходит через неожиданный proxy;
- `Settings → Privacy and security → Security → Use secure DNS`: временно отключите Secure DNS или выберите системный DNS;
- `chrome://net-internals/#dns`: нажмите `Clear host cache`;
- `chrome://net-internals/#sockets`: нажмите `Flush socket pools`;
- для Chromium в snap выполните аналогичную проверку snap-окружения:

```bash
getent hosts dockge.local.kubehut.top
snap run --shell chromium
getent hosts dockge.local.kubehut.top
cat /etc/resolv.conf
```

## Упаковка в PHAR

```bash
php -d phar.readonly=0 scripts/build-phar.php
```

Команда создаёт `build/docker-cli.phar`.
