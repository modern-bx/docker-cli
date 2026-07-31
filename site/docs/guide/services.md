# Базовые сервисы

Системный compose-файл запускает:

- `dnsdock` для автоматических DNS-имён контейнеров;
- `traefik` для HTTPS-доступа и выпуска сертификатов через Cloudflare DNS challenge;
- `dockhand` как веб-интерфейс мониторинга и управления Docker и compose-стеками;
- `mysql` на образе `mysql:8.0`;
- `mydumper` на образе `mydumper/mydumper:v1.0.3-1` (профиль `tools`, запускается только командами дампа и загрузки);
- `postgres` на образе `postgres:18`, актуальной стабильной ветке PostgreSQL на 12 июля 2026 года;
- `adminer` как HTTPS web-интерфейс для работы с базами данных;
- `mailpit` как локальный SMTP-сервер и web-интерфейс для просмотра писем;
- `openresty` для отдачи статики зарегистрированных проектов через проектные хосты вида `web-<project-name>.${BASE_HOST}`.

## Dockhand

После настройки `BASE_HOST` Dockhand доступен по адресу `https://dockhand.<ваш-домен>`. При первом открытии интерфейс предложит создать локального администратора. Данные Dockhand хранятся в именованном Docker volume `dockhand-data`.

## MySQL, PostgreSQL и Adminer

MySQL и PostgreSQL не публикуют порты на хост: они доступны только из сети `docker-cli` по стандартным портам `3306` и `5432` и коротким алиасам `mysql` / `postgres` либо DNS-именам `mysql.${BASE_HOST}` / `postgres.${BASE_HOST}`.

Файлы, генерируемые системными контейнерами баз данных, лежат рядом с системным compose-файлом в `~/.config/docker-cli/compose/system/data`:

- для MySQL используются `data/mysql/data` и `data/mysql/logs`;
- для PostgreSQL — `data/postgres/data` и `data/postgres/logs`.

Adminer публикуется только через Traefik с TLS и доступен по адресу `https://adminer.<ваш-домен>`.

## Mailpit

Mailpit принимает почту внутри сети `docker-cli` по адресу `mailpit:1025`. PHP-FPM настроен на этот SMTP-сервер через `msmtp`, поэтому письма, отправленные стандартной функцией PHP `mail()`, автоматически попадают в Mailpit. Web-интерфейс доступен через Traefik по адресу `https://mailpit.<ваш-домен>`.

База данных Mailpit со всеми письмами хранится на хосте в `~/.config/docker-cli/compose/system/data/mailpit` и монтируется в контейнер как `/data`. Благодаря этому письма сохраняются после пересоздания контейнера.

## OpenResty и проектные хосты

OpenResty отдаёт статику зарегистрированных проектов через проектные хосты вида `web-<project-name>.${BASE_HOST}`. HTTP-запросы к таким хостам автоматически перенаправляются на HTTPS.

Перед стартом OpenResty явно записывает `user root;` в основной `nginx.conf`, чтобы именно nginx worker, а не только master-процесс, работал от root. Это нужно потому, что проектные файлы монтируются read-only из контейнера, а локальные директории проектов часто лежат внутри домашней директории с правами, недоступными пользователю `nobody` внутри контейнера.

Для проектов в `/home` путь монтируется в контейнер без префикса, чтобы абсолютные пути и symlink-и внутри проекта резолвились так же, как на хосте. Остальные пути доступны через fallback-mount `/host`.


### Порт OpenResty

Порт, на котором OpenResty слушает проектные virtual host-и внутри compose-сети, задаётся переменной `OPENRESTY_PORT` в системном `.env`:

```dotenv
OPENRESTY_PORT=80
```

Значение по умолчанию — `80`. Если порт меняется в уже инициализированном окружении, выполните:

```bash
docker-cli config:init --migrate --rebuild
docker-cli system:restart
```

`--rebuild` пересобирает конфиги `config/openresty/hosts/*.web.conf` с новым `listen`-портом, а `system:restart` перезапускает OpenResty и прокси-слой.

### Внешний OpenResty на хост-машине

Если порты `80` и `443` на хост-машине занимает внешний OpenResty вне контейнеров, он должен только маршрутизировать нужный wildcard-хост на внутренний прокси без терминации TLS. В этом режиме сертификаты остаются внутри docker-cli окружения, а внешний OpenResty передаёт TCP-трафик «как есть».

1. В системном `.env` docker-cli укажите свободные published-порты внутреннего прокси, например:

   ```dotenv
   # пример: внутренний прокси опубликован на localhost и не конфликтует с внешним OpenResty
   TRAEFIK_HTTP_PORT=127.0.0.1:8080
   TRAEFIK_HTTPS_PORT=127.0.0.1:8443
   ```

2. Во внешнем OpenResty используйте обычный `http`-proxy для порта `80` и `stream` с `ssl_preread` для порта `443`, чтобы TLS передавался внутрь без терминации:

   ```nginx
   http {
       server {
           listen 80;
           server_name example.test *.example.test;

           location / {
               proxy_pass http://127.0.0.1:8080;
               proxy_set_header Host $host;
               proxy_set_header X-Real-IP $remote_addr;
               proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
               proxy_set_header X-Forwarded-Proto $scheme;
           }
       }
   }

   stream {
       map $ssl_preread_server_name $docker_cli_https_upstream {
           ~^(.+\.)?example\.test$ 127.0.0.1:8443;
           default 127.0.0.1:8443;
       }

       server {
           listen 443;
           ssl_preread on;
           proxy_pass $docker_cli_https_upstream;
       }
   }
   ```

   Замените `example.test` на значение `BASE_HOST`. Для wildcard-домена `*.example.test` регулярное выражение `~^(.+\.)?example\.test$` пропускает и сам базовый домен, и любые поддомены. Внешний OpenResty не должен содержать директивы `ssl_certificate` для этого wildcard-хоста: TLS-сессия должна завершаться внутри docker-cli окружения.

## Traefik, DNS-алиасы и TLS

`BASE_HOST` не должен оставаться пустым: укажите собственный домен перед запуском окружения. Подготовка зоны Cloudflare описана в разделе [Настройка домена в Cloudflare](./cloudflare.md).

DNS-имена Adminer и проектные имена вида `web-<project-name>.${BASE_HOST}` регистрируются на контейнер Traefik через `DNSDOCK_ALIAS`, поэтому браузер попадает в HTTPS-router Traefik, а не напрямую в контейнер приложения.

Для `websecure` и router-а OpenResty явно указан wildcard-домен `*.${BASE_HOST}` через Cloudflare DNS challenge, чтобы regex-router проектных доменов получал Let's Encrypt сертификат, а не дефолтный самоподписанный сертификат Traefik.

ACME DNS challenge использует публичные резолверы `1.1.1.1` и `8.8.8.8`, чтобы Traefik не определял локальную зону dnsdock как Cloudflare-зону.

## Локализация CLI

Язык сообщений CLI задаётся параметром `APP_LOCALE` в сгенерированном `.env`. По умолчанию используется русский (`ru`).
