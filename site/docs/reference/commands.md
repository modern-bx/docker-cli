# Справочник команд

## Конфигурация

### `bin/docker-cli config:init`

Создаёт системную конфигурацию в `~/.config/docker-cli/compose/system` и добавляет `.env` / `compose.yaml`, если они отсутствуют.

Опции:

- `--update` — перезаписывает статические файлы конфигурации из шаблонов после подтверждения.
- `--force` — отключает интерактивное подтверждение для `--update`.
- `--migrate` — добавляет отсутствующие параметры в редактируемые файлы.
- `--rebuild` — пересобирает значения, зависящие от зарегистрированных проектов.

Пример:

```bash
bin/docker-cli config:init --update --force --migrate --rebuild
```

### `bin/docker-cli config:seed`

Заполняет пустые сидируемые значения в `.env`: администратора Dockge и пароли MySQL/PostgreSQL.

```bash
bin/docker-cli config:seed
```

Автоматический режим без интерактивного подтверждения:

```bash
bin/docker-cli config:seed --yes
```

## Проекты

### `bin/docker-cli project:up [name]`

Регистрирует Laravel, Symfony, Bitrix или Bitrix24 проект из текущей директории или вложенного пути.

```bash
bin/docker-cli project:up my-project
```

Если `name` не указан, CLI генерирует свободный идентификатор в формате `adjective-animal`. Перед созданием проектных баз команда выполняет `system:start`, чтобы системные сервисы были запущены.

Опция:

```bash
bin/docker-cli project:up my-project --no-restart
```

`--no-restart` пропускает перезапуск общего пула проектных сервисов.

### `bin/docker-cli project:down`

Удаляет регистрацию текущего проекта.

```bash
bin/docker-cli project:down
```

Опция:

```bash
bin/docker-cli project:down --no-restart
```

`--no-restart` пропускает перезапуск общего пула проектных сервисов.

## Системное окружение

### `bin/docker-cli system:start` / `bin/docker-cli start`

Запускает системный compose-проект:

```bash
bin/docker-cli system:start
bin/docker-cli start
```

### `bin/docker-cli system:stop` / `bin/docker-cli stop`

Останавливает системный compose-проект, удаляет orphan-контейнеры и общую сеть `docker-cli`:

```bash
bin/docker-cli system:stop
bin/docker-cli stop
```

### `bin/docker-cli system:restart` / `bin/docker-cli restart`

Последовательно выполняет stop и start:

```bash
bin/docker-cli system:restart
bin/docker-cli restart
```

## Образы

### `docker-cli image:build`

Собирает кастомные образы из исходников.

```bash
docker-cli image:build
```

Полезные варианты:

```bash
docker-cli image:build --dry-run
docker-cli image:build --tag=1.0.0
```

### `docker-cli image:publish`

Публикует собранные кастомные образы в registry.

```bash
docker-cli image:publish
```

Полезные варианты:

```bash
docker-cli image:publish --dry-run
docker-cli image:publish --tag=1.0.0
```

## Дистрибутивы Битрикс

### `bin/docker-cli bitrix:get-installer`

Скачивает архив дистрибутива 1С-Битрикс или 1С-Битрикс24. По умолчанию выбирается продукт `bitrix`, редакция `start` и текущая директория. Если целевой файл уже существует, команда завершается с ошибкой.

Опции:

- `--product` — продукт: `bitrix` или `bitrix24`.
- `--edition` — редакция. Для `bitrix`: `start`, `standard`, `small_business`, `expert`, `business`; для `bitrix24`: `business`, `enterprise`, `enterprise_postgresql`.
- `--path` — файл для сохранения или директория, куда архив будет помещен с серверным именем файла.
- `--extract` — распаковать архив в директорию, где он лежит, и удалить архив после распаковки.

Примеры:

```bash
bin/docker-cli bitrix:get-installer
bin/docker-cli bitrix:get-installer --product=bitrix24 --edition=enterprise --path=./dist --extract
```

## Упаковка PHAR

Собирает `build/docker-cli.phar`:

```bash
php -d phar.readonly=0 scripts/build-phar.php
```

## Сайт документации

Команды выполняются из директории `site`.

### `npm run dev`

Запускает VitePress dev-сервер на `0.0.0.0`:

```bash
cd site
npm run dev
```

### `npm run build`

Собирает production-версию в `site/dist`:

```bash
cd site
npm run build
```

### `npm run preview`

Запускает preview production-сборки на `0.0.0.0`:

```bash
cd site
npm run preview
```
