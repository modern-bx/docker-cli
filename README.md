# docker-cli

Консольная утилита для управления локальными Docker-окружениями.

## Команды

Перед первым запуском создайте конфигурацию:

```bash
bin/docker-cli config:init
```

Команда создаёт `~/.config/docker-cli/compose/system`, а также файлы `.env` и `compose.yaml`, если они отсутствуют. Уже существующие файлы не перезаписываются. Опция `--update` после интерактивного подтверждения перезаписывает статические файлы конфигурации из шаблонов; сейчас это `compose.yaml`. Опция `--migrate` добавляет в редактируемые файлы отсутствующие параметры из шаблонов; сейчас это `.env`. Опция `--rebuild` пересобирает значения, зависящие от текущего состава зарегистрированных проектов; сейчас это `PROJECT_WEB_DNSDOCK_ALIAS` в `.env`. Опции `--update`, `--migrate` и `--rebuild` можно указывать одновременно.

После проверки параметров в `.env` заполните генерируемые секреты:

```bash
bin/docker-cli config:seed
```

Команда спросит подтверждение и заполнит только пустые сидируемые значения в `.env`. Сейчас она заполняет `DOCKGE_ADMIN_USERNAME=admin`, случайный `DOCKGE_ADMIN_PASSWORD`, а также случайные пароли `MYSQL_ROOT_PASSWORD`, `MYSQL_PASSWORD` и `POSTGRES_PASSWORD`. Уже заполненные значения не меняются. Имена баз и пользователей MySQL/PostgreSQL предзаполнены в `.env` значением `system`, их можно поменять вручную до первого запуска. Для автоматических сценариев можно использовать `bin/docker-cli config:seed --yes`.

Проект Laravel, Symfony, Bitrix или Bitrix24 можно поднять и зарегистрировать из корня проекта или любой вложенной директории:

```bash
bin/docker-cli project:up [my-project]
```

Команда определяет фреймворк, создает запись `~/.config/docker-cli/projects/my-project/project.yaml` с именем проекта, кодовым именем фреймворка, корнем проекта и document root, а также записывает метаданные проекта в `.docker-cli.yaml` в корне найденного проекта. После изменения регистрации пересобираются проектные хосты OpenResty в `~/.config/docker-cli/compose/system/config/openresty/hosts`, обновляется переменная `PROJECT_WEB_DNSDOCK_ALIAS` в системном `.env` для DNS-алиасов проектных доменов на Traefik и пересоздается пул общих проектных сервисов. Если имя проекта не указано, генерируется свободный идентификатор в формате `adjective-animal`, например `precise-pangolin`. Если имя не соответствует конвенции, проект с таким именем уже зарегистрирован или фреймворк не определен, команда завершается ошибкой. Для пропуска перезапуска используйте `--no-restart`.

Чтобы удалить регистрацию текущего проекта, выполните команду из корня проекта или любой вложенной директории:

```bash
bin/docker-cli project:down
```

Команда читает имя проекта из `.docker-cli.yaml`, удаляет соответствующую директорию в `~/.config/docker-cli/projects`, удаляет `.docker-cli.yaml`, пересобирает проектные хосты OpenResty, обновляет `PROJECT_WEB_DNSDOCK_ALIAS` и пересоздает пул общих проектных сервисов. Если фреймворк не определен или файл метаданных отсутствует, команда завершается ошибкой. Для пропуска перезапуска используйте `--no-restart`.

После этого можно запускать и останавливать системное окружение:

```bash
bin/docker-cli system:start
bin/docker-cli system:stop
```

`start` выполняет `docker compose up -d` для проекта `docker-cli`, а `stop` выполняет `docker compose down --remove-orphans` и удаляет общую сеть `docker-cli`. Если `.env` или `compose.yaml` отсутствуют, команды завершаются понятной ошибкой и предлагают выполнить `docker-cli config:init`.


## Сборка и публикация кастомных образов

Кастомные образы из исходников собираются командой:

```bash
docker-cli image:build
```

Публикация в registry выполняется отдельно:

```bash
docker-cli image:publish
```

Сейчас кастомный образ один: `php-fpm-8.2`. По умолчанию команды используют registry `ghcr.io`, namespace `whiskyjs` и составное имя образа `docker-cli/php-fpm-8.2`, то есть публикуемый ref выглядит как `ghcr.io/whiskyjs/docker-cli/php-fpm-8.2:<tag>`. Тег берётся из `SOURCE_IMAGE_TAG`, если он задан; иначе используется самый новый semver-тег, достижимый из текущей ветки git-репозитория, без префикса `v`; если подходящий git-тег не найден, используется `default`. Для ручной проверки команд без запуска Docker используйте `--dry-run`, для явного тега — `--tag=1.0.0`.

Настройки сборки и публикации лежат в системном `.env`: `SOURCE_IMAGE_REGISTRY`, `SOURCE_IMAGE_NAMESPACE`, `SOURCE_IMAGE_TAG` и `SOURCE_IMAGE_DOCKER_BUILDKIT`. По умолчанию `SOURCE_IMAGE_DOCKER_BUILDKIT=0`, чтобы обойти сетевые проблемы BuildKit при сборке PHP-FPM.

### Публикация в GHCR

Для публикации нужен GitHub Personal Access Token с правами `write:packages` и, если образ приватный, `read:packages`. Войдите в GHCR под своим GitHub-логином:

```bash
echo "$GITHUB_TOKEN" | docker login ghcr.io -u <github-login> --password-stdin
```

После логина соберите и опубликуйте образы:

```bash
docker-cli image:build
docker-cli image:publish
```

Если нужно опубликовать образ в другой GitHub namespace или organization, измените `SOURCE_IMAGE_NAMESPACE` в `~/.config/docker-cli/compose/system/.env`. Для проверки без Docker-команд используйте `docker-cli image:build --dry-run` и `docker-cli image:publish --dry-run`.

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

OpenResty перед стартом явно записывает `user root;` в основной `nginx.conf`, чтобы именно nginx worker, а не только master-процесс, работал от root. Это нужно потому, что проектные файлы монтируются read-only из контейнера, а локальные директории проектов часто лежат внутри домашней директории с правами, недоступными пользователю `nobody` внутри контейнера. Для проектов в `/home` путь монтируется в контейнер без префикса, чтобы абсолютные пути и symlink-и внутри проекта резолвились так же, как на хосте; остальные пути остаются доступны через fallback-mount `/host`.

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

## Xdebug

Образ `php-fpm-8.2` устанавливает расширение Xdebug и включает только режим пошаговой отладки. Профайлинг, трассировка, coverage и остальные режимы не включаются.

### Порты проектов

Для браузера используется единый trigger cookie `XDEBUG_TRIGGER=docker-cli`, но каждый зарегистрированный проект получает отдельный порт IDE. Это позволяет держать несколько проектов открытыми в PhpStorm одновременно: cookie одна и та же, а входящие debug-соединения приходят на разные порты.

При `docker-cli project:up` в `~/.config/docker-cli/projects/<project>/project.yaml` сохраняется вычисленная настройка:

```yaml
data:
  project:
    xdebug:
      client_port: 9004
```

Алгоритм не использует стандартный порт Xdebug `9003`: первый кандидат — `9004`. Если такой порт уже записан у любого зарегистрированного проекта, кандидат увеличивается на `1`, после чего список проектов проверяется заново. Первый порт, которого нет ни в одном `project.yaml`, сохраняется за новым проектом. OpenResty при генерации vhost-а подставляет этот порт в FastCGI-параметр `PHP_VALUE`, поэтому browser-request конкретного проекта подключается именно к своему порту IDE.

### Активация из браузера

Xdebug стартует только по trigger-у. Для browser-сценариев установите cookie:

```text
XDEBUG_TRIGGER=docker-cli
```

Удобнее всего сделать это расширением браузера для Xdebug. Значение cookie одинаковое для всех проектов. Разделение проектов выполняется не cookie, а портом `xdebug.client_port`, который docker-cli хранит в конфиге проекта и передаёт в PHP-FPM через OpenResty.

### Активация из консоли внутри контейнера

При входе в PHP-FPM контейнер через интерактивный shell docker-cli подключает `/etc/profile.d/docker-cli-xdebug.sh`. Скрипт ищет вверх от текущей директории файл `.docker-cli.yaml`, по имени проекта читает `~/.config/docker-cli/projects/<project>/project.yaml` и выставляет:

```bash
XDEBUG_CONFIG="client_host=host.docker.internal client_port=<порт проекта> idekey=PHPSTORM"
```

Поэтому типовой сценарий выглядит так:

```bash
docker exec -it docker-cli-php-fpm-8.2 bash
cd /home/user/projects/my-project
export XDEBUG_TRIGGER=docker-cli
php bin/console app:command
```

Если после входа в контейнер вы меняете директорию командой `cd`, wrapper обновляет `XDEBUG_CONFIG` под новый проект. Для запуска без интерактивного shell можно задать переменные явно:

```bash
docker exec -e XDEBUG_TRIGGER=docker-cli \
  -e 'XDEBUG_CONFIG=client_host=host.docker.internal client_port=9004 idekey=PHPSTORM' \
  docker-cli-php-fpm-8.2 php /home/user/projects/my-project/bin/console app:command
```

### Параметры `zz-xdebug.ini`

Файл `config/php-fpm-8.2/php/conf.d/zz-xdebug.ini` содержит только настройки, необходимые для пошаговой отладки:

- `xdebug.mode=debug` — включает только step debugger. В принципе Xdebug умеет несколько режимов (`debug`, `develop`, `coverage`, `profile`, `trace`, `gcstats`), но каждый дополнительный режим добавляет накладные расходы или генерирует артефакты. В docker-cli оставлен только `debug`, потому что профайлинг и трассировка не требуются.
- `xdebug.start_with_request=trigger` — Xdebug не пытается подключаться к IDE на каждый HTTP/CLI запуск, а стартует только при наличии trigger-а. Для браузера trigger — cookie/request-параметр/header `XDEBUG_TRIGGER`; для CLI — переменная окружения `XDEBUG_TRIGGER`. Это снижает шум и не замедляет обычные запросы.
- `xdebug.trigger_value=docker-cli` — ограничивает допустимое значение trigger-а. В нашем окружении browser-cookie должна быть `XDEBUG_TRIGGER=docker-cli`; случайная cookie или переменная с другим значением отладку не включит.
- `xdebug.client_host=host.docker.internal` — адрес IDE с точки зрения контейнера. В compose для PHP-FPM добавлен `extra_hosts: host.docker.internal:host-gateway`, поэтому контейнер стабильно обращается к Docker host, где запущен PhpStorm.
- `xdebug.client_port=9003` — базовый fallback Xdebug. Для проектов docker-cli этот стандартный порт намеренно не используется: browser-запросы получают проектный порт из OpenResty, а CLI-запуски получают его через `XDEBUG_CONFIG`. Значение `9003` остаётся только безопасным дефолтом, если PHP запущен вне зарегистрированного проекта.
- `xdebug.idekey=PHPSTORM` — IDE key, который PhpStorm умеет принимать по умолчанию. В большинстве конфигураций PhpStorm достаточно слушать порт и правильно настроить path mappings; отдельная фильтрация по IDE key не нужна.
- `xdebug.log_level=0` — отключает подробный лог Xdebug. Это сохраняет контейнеры чистыми при обычной работе. Для диагностики можно временно добавить `xdebug.log=/tmp/xdebug.log` и поднять `xdebug.log_level`, но в шаблоне docker-cli это не включено.

Само расширение подключается стандартным ini-файлом, который создаёт `docker-php-ext-enable xdebug` при сборке образа. `zz-xdebug.ini` не содержит `zend_extension`, чтобы не загрузить Xdebug дважды.

### Настройка PhpStorm

1. Откройте `Settings/Preferences → PHP → Debug`.
2. В блоке Xdebug добавьте порты всех нужных проектов в поле `Debug port`, например `9004,9005,9006`. Порт конкретного проекта смотрите в `~/.config/docker-cli/projects/<project>/project.yaml` в `data.project.xdebug.client_port`.
3. Включите `Can accept external connections` / нажмите `Start Listening for PHP Debug Connections` на панели PhpStorm.
4. Откройте `Settings/Preferences → PHP → Servers` и создайте server для каждого web-домена проекта:
   - `Name`: удобно указать домен проекта, например `web-my-project.local.kubehut.top`;
   - `Host`: тот же домен, например `web-my-project.local.kubehut.top`;
   - `Port`: `443`;
   - `Debugger`: `Xdebug`;
   - включите `Use path mappings`;
   - локальный корень проекта сопоставьте с тем же абсолютным путём внутри контейнера, если проект лежит в `/home`, например `/home/user/projects/my-project`; для проектов вне `/home` путь внутри контейнера будет `/host/<абсолютный-путь-на-хосте>`.
5. Для браузера установите cookie `XDEBUG_TRIGGER=docker-cli` на домен проекта и обновите страницу. PhpStorm должен принять соединение на порту проекта.
6. Для CLI войдите в контейнер, перейдите в директорию проекта, выполните `export XDEBUG_TRIGGER=docker-cli` и запустите PHP-скрипт. Если breakpoint не сработал, проверьте, что PhpStorm слушает порт проекта, а `echo "$XDEBUG_CONFIG"` внутри контейнера содержит тот же `client_port`.
