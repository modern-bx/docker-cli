# Сборка и публикация образов

Кастомные образы из исходников собираются командой:

```bash
docker-cli image:build
```

Публикация в registry выполняется отдельно:

```bash
docker-cli image:publish
```

Команды собирают и публикуют два кастомных образа: `php-fpm-8.2` и `playwright`.

## Registry, vendor/namespace и тег

Перед сборкой или публикацией укажите registry и свой vendor/namespace в системном `.env`:

```dotenv
SOURCE_IMAGE_REGISTRY=ghcr.io
SOURCE_IMAGE_NAMESPACE=<ваш-vendor-or-namespace>
```

`SOURCE_IMAGE_NAMESPACE` обязателен. Если он не задан, команды сборки и публикации завершаются ошибкой, чтобы не публиковать и не тегировать образы в чужой namespace.

Публикуемый ref выглядит так:

```text
<SOURCE_IMAGE_REGISTRY>/<SOURCE_IMAGE_NAMESPACE>/docker-cli/php-fpm-8.2:<tag>
<SOURCE_IMAGE_REGISTRY>/<SOURCE_IMAGE_NAMESPACE>/docker-cli/playwright:<tag>
```

Тег выбирается так:

1. берётся из `SOURCE_IMAGE_TAG`, если он задан;
2. иначе используется самый новый semver-тег, достижимый из текущей ветки git-репозитория, без префикса `v`;
3. если подходящий git-тег не найден, используется `default`.

Для ручной проверки команд без запуска Docker используйте:

```bash
docker-cli image:build --dry-run
docker-cli image:publish --dry-run
```

Для явного тега используйте:

```bash
docker-cli image:build --tag=1.0.0
docker-cli image:publish --tag=1.0.0
```

## Настройки

Настройки сборки и публикации лежат в системном `.env`:

- `SOURCE_IMAGE_REGISTRY` — registry для образов, например `ghcr.io`;
- `SOURCE_IMAGE_NAMESPACE` — ваш vendor, GitHub namespace или organization;
- `SOURCE_IMAGE_TAG`;
- `SOURCE_IMAGE_DOCKER_BUILDKIT`.

По умолчанию `SOURCE_IMAGE_DOCKER_BUILDKIT=0`, чтобы обойти сетевые проблемы BuildKit при сборке PHP-FPM.

## Публикация в GHCR

Для публикации нужен GitHub Personal Access Token с правами `write:packages` и, если образ приватный, `read:packages`.

Войдите в GHCR под своим GitHub-логином:

```bash
echo "$GITHUB_TOKEN" | docker login ghcr.io -u <github-login> --password-stdin
```

После логина задайте свой GitHub namespace или organization в `SOURCE_IMAGE_NAMESPACE`, соберите и опубликуйте образы:

```bash
docker-cli image:build
docker-cli image:publish
```

Для проверки без Docker-команд используйте `docker-cli image:build --dry-run` и `docker-cli image:publish --dry-run`.
