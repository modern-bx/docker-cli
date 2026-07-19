# Базовые сервисы

Системный compose-файл запускает:

- `dnsdock` для автоматических DNS-имён контейнеров;
- `traefik` для HTTPS-доступа и выпуска сертификатов через Cloudflare DNS challenge;
- `dockge` как веб-интерфейс мониторинга и управления compose-стеками;
- `dockge-seed` как одноразовый контейнер, который при первом запуске создаёт администратора Dockge через setup-событие Socket.IO и спокойно завершается, если Dockge уже инициализирован;
- `mysql` на образе `mysql:8.0`;
- `postgres` на образе `postgres:18`, актуальной стабильной ветке PostgreSQL на 12 июля 2026 года;
- `adminer` как HTTPS web-интерфейс для работы с базами данных;
- `openresty` для отдачи статики зарегистрированных проектов через проектные хосты вида `web-<project-name>.${BASE_HOST}`.

## Dockge

Dockge не предоставляет штатные переменные окружения для bootstrap-администратора: в upstream это обсуждалось как feature request. Поэтому bootstrap реализован отдельным одноразовым seed-контейнером, а логин/пароль берутся из `.env`.

После настройки `BASE_HOST` Dockge доступен по адресу `https://dockge.<ваш-домен>`.

Логин администратора Dockge по умолчанию — `admin`, пароль записывает команда `config:seed`.

## MySQL, PostgreSQL и Adminer

MySQL и PostgreSQL не публикуют порты на хост: они доступны только из сети `docker-cli` по стандартным портам `3306` и `5432` и коротким алиасам `mysql` / `postgres` либо DNS-именам `mysql.${BASE_HOST}` / `postgres.${BASE_HOST}`.

Файлы, генерируемые системными контейнерами баз данных, лежат рядом с системным compose-файлом в `~/.config/docker-cli/compose/system/data`:

- для MySQL используются `data/mysql/data` и `data/mysql/logs`;
- для PostgreSQL — `data/postgres/data` и `data/postgres/logs`.

Adminer публикуется только через Traefik с TLS и доступен по адресу `https://adminer.<ваш-домен>`.

## OpenResty и проектные хосты

OpenResty отдаёт статику зарегистрированных проектов через проектные хосты вида `web-<project-name>.${BASE_HOST}`.

Перед стартом OpenResty явно записывает `user root;` в основной `nginx.conf`, чтобы именно nginx worker, а не только master-процесс, работал от root. Это нужно потому, что проектные файлы монтируются read-only из контейнера, а локальные директории проектов часто лежат внутри домашней директории с правами, недоступными пользователю `nobody` внутри контейнера.

Для проектов в `/home` путь монтируется в контейнер без префикса, чтобы абсолютные пути и symlink-и внутри проекта резолвились так же, как на хосте. Остальные пути доступны через fallback-mount `/host`.

## Traefik, DNS-алиасы и TLS

`BASE_HOST` не должен оставаться пустым: укажите собственный домен перед запуском окружения. Подготовка зоны Cloudflare описана в разделе [Настройка домена в Cloudflare](./cloudflare.md).

DNS-имена Adminer и проектные имена вида `web-<project-name>.${BASE_HOST}` регистрируются на контейнер Traefik через `DNSDOCK_ALIAS`, поэтому браузер попадает в HTTPS-router Traefik, а не напрямую в контейнер приложения.

Для `websecure` и router-а OpenResty явно указан wildcard-домен `*.${BASE_HOST}` через Cloudflare DNS challenge, чтобы regex-router проектных доменов получал Let's Encrypt сертификат, а не дефолтный самоподписанный сертификат Traefik.

ACME DNS challenge использует публичные резолверы `1.1.1.1` и `8.8.8.8`, чтобы Traefik не определял локальную зону dnsdock как Cloudflare-зону.

## Локализация CLI

Язык сообщений CLI задаётся параметром `APP_LOCALE` в сгенерированном `.env`. По умолчанию используется русский (`ru`).
