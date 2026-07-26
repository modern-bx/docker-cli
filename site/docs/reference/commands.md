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

Регистрирует Laravel, Symfony, Bitrix или Bitrix24 проект из текущей директории или вложенного пути. Опция `--force` разрешает регистрацию, даже если определить фреймворк не удалось.

```bash
bin/docker-cli project:up my-project
```

Если `name` не указан, CLI генерирует свободный идентификатор в формате `adjective-animal`. Перед созданием проектных баз команда выполняет `system:start`, чтобы системные сервисы были запущены.

Опция:

```bash
bin/docker-cli project:up my-project --no-restart
```

`--no-restart` пропускает перезапуск общего пула проектных сервисов.

### `bin/docker-cli project:list`

Выводит кодовые имена всех зарегистрированных проектов, по одному на строку:

```bash
bin/docker-cli project:list
```

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

### `bin/docker-cli project:show [project]`

Выводит содержимое `project.yaml` текущего проекта без преобразований. Кодовое имя проекта можно указать явно, чтобы вызвать команду из любой директории.

```bash
bin/docker-cli project:show
bin/docker-cli project:show my-project
```

### `bin/docker-cli project:config-get <path>`

Читает значение из секции `data` конфигурации текущего проекта. Путь задаётся через точку:

```bash
bin/docker-cli project:config-get databases.mysql.password
```

### `bin/docker-cli project:config-set <path> <value>`

Записывает значение в секцию `data` конфигурации текущего проекта и создаёт отсутствующие части пути:

```bash
bin/docker-cli project:config-set databases.mysql.database app_database
```

### `bin/docker-cli project:wipe`

Удаляет из корня проекта все файлы и директории, включая скрытые, но сохраняет служебную директорию `.docker-cli`. Проект определяется по текущей директории либо через `--project`:

```bash
bin/docker-cli project:wipe
bin/docker-cli project:wipe --project=my-project
```

## Данные проекта

Команды без явного аргумента или опции проекта определяют его по текущей директории.

### `bin/docker-cli data:init [project]`

Создаёт базы и пользователей MySQL и PostgreSQL зарегистрированного проекта. `--rebuild` предварительно удаляет существующие базы и пользователей:

```bash
bin/docker-cli data:init
bin/docker-cli data:init my-project --rebuild
```

### `bin/docker-cli data:drop [project]`

Удаляет базы и пользователей MySQL и PostgreSQL проекта:

```bash
bin/docker-cli data:drop
bin/docker-cli data:drop my-project
```

### `bin/docker-cli data:wipe [project]`

Удаляет таблицы из баз MySQL и PostgreSQL, не удаляя сами базы и пользователей:

```bash
bin/docker-cli data:wipe
bin/docker-cli data:wipe my-project
```

### `bin/docker-cli data:dump <path>`

Создаёт дамп выбранной базы. Обязательная опция `--dbms` принимает `mysql` или `postgres`; проект задаётся через `--project` или определяется по текущей директории.

```bash
bin/docker-cli data:dump --dbms=mysql ./backup.sql
bin/docker-cli data:dump --dbms=postgres --project=my-project ./backup.sql
```

Опция `--compress=zip` после создания дампа записывает архив `<path>.zip` с SQL-файлом в корне и удаляет исходный дамп:

```bash
bin/docker-cli data:dump --dbms=mysql --compress=zip ./backup.sql
# Результат: ./backup.sql.zip
```

### `bin/docker-cli data:apply <path>...`

Последовательно применяет к выбранной базе SQL-файлы. Обязательная опция `--dbms` принимает `mysql` или `postgres`; проект задаётся через `--project` или определяется по текущей директории. Можно передать несколько файлов, директорий и glob-выражений:

```bash
bin/docker-cli data:apply --dbms=mysql ./schema.sql ./fixtures/
bin/docker-cli data:apply --dbms=postgres --project=my-project './migrations/*.sql'
```

Поддерживаются SQL-файлы и ZIP-архивы. При поиске в директории рассматриваются только находящиеся непосредственно в ней файлы с расширением `.sql` и `.zip`. Из каждого архива временно извлекаются только SQL-файлы верхнего уровня: содержимое вложенных директорий игнорируется, а файлы применяются в порядке возрастания имени. ZIP-файлы также можно передавать напрямую или находить glob-выражением:

```bash
bin/docker-cli data:apply --dbms=mysql ./backup.sql.zip
bin/docker-cli data:apply --dbms=mysql './backups/*'
```

## Административная панель

### `bin/docker-cli panel:user-add`

Интерактивно запрашивает логин в формате email и пароль, затем добавляет пользователя панели. Пароль сохраняется в виде солёного хеша в `~/.config/docker-cli/panel/users.yaml`.

```bash
bin/docker-cli panel:user-add
```

### `bin/docker-cli panel:user-delete <логин>`

Удаляет пользователя панели, если пользователь с таким email существует.

```bash
bin/docker-cli panel:user-delete admin@example.com
```

Случайная соль `PANEL_PASSWORD_SALT` создаётся в системном `.env` командой `config:init`.

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
docker-cli image:build --no-cache
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

## Сценарии Playwright

### `docker-cli play:run <script>`

Запускает JavaScript-сценарий из `~/.config/docker-cli/playwright/scripts`; расширение `.js` можно опустить. `--browser` выбирает `chromium`, `firefox` или `webkit`, а `--show` открывает управляемый браузер через локальный noVNC viewer:

```bash
docker-cli play:run bitrix/setup
docker-cli play:run --browser=firefox --show bitrix/setup
```

Подробное описание сценариев и их данных приведено в разделе [Playwright](../guide/playwright.md).

## Дистрибутивы Битрикс

### `bin/docker-cli bitrix:get-installer`

Скачивает архив дистрибутива 1С-Битрикс или 1С-Битрикс24. По умолчанию выбирается продукт `bitrix`; редакция по умолчанию зависит от продукта: `start` для `bitrix` и `business` для `bitrix24`. Архив сохраняется в текущую директорию. Если целевой файл уже существует, команда завершается с ошибкой.

Перед скачиванием команда выполняет `HEAD`-запрос к URL дистрибутива. Если `Content-Length` совпадает с размером файла в кеше `~/.config/docker-cli/cache/bitrix-get-installer/distro`, архив берётся из кеша без повторной загрузки. Если размер изменился или кеша нет, команда скачивает свежий архив и обновляет кеш без отдельного версионирования.

Опции:

- `--product` — продукт: `bitrix` или `bitrix24`.
- `--edition` — редакция. Для `bitrix`: `start` (по умолчанию), `standard`, `small_business`, `expert`, `business`; для `bitrix24`: `business` (по умолчанию), `enterprise`, `enterprise_postgresql`.
- `--path` — файл для сохранения или директория, куда архив будет помещен с серверным именем файла; по умолчанию используется текущая директория.
- `--extract` — распаковать архив в директорию, где он лежит, и удалить архив после распаковки.

Примеры:

```bash
bin/docker-cli bitrix:get-installer
bin/docker-cli bitrix:get-installer --product=bitrix24 --extract
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
