# Бэкапы проектов

`docker-cli` хранит три независимых компонента бэкапа: файлы проекта, MySQL и PostgreSQL. Их можно создавать, восстанавливать и удалять отдельно. Панель группирует компоненты с одинаковым именем в один составной бэкап и показывает размер и число томов.

## Где хранятся копии

Без дополнительных настроек копии лежат в проекте:

- `.docker-cli/backups/tree/<имя>` — файлы;
- `.docker-cli/backups/mysql/<имя>` — MySQL;
- `.docker-cli/backups/postgres/<имя>` — PostgreSQL.

В **Настройки → Бэкапы** можно добавить один или несколько общих каталогов. Каждому нужны уникальный код и признак расположения по умолчанию. Каталог должен уже существовать и быть доступен для чтения, записи и листинга. В общем каталоге CLI создаёт подкаталоги `tree`, `mysql` и `postgres`.

Для CLI общее хранилище выбирается через `--location=<код>` вместе с `--name`. `--path` всегда означает прямой путь к одной копии и с `--location` не сочетается.

## Составной бэкап в панели

1. Откройте проект и вкладку **Бэкапы**.
2. Нажмите создание бэкапа, задайте общее имя и отметьте нужные части: файлы, MySQL и/или PostgreSQL.
3. Для файлов выберите стратегию, сжатие и, если нужно, разбиение на тома. Для баз задайте параллелизм.
4. Выберите расположение. Панель поставит в очередь по одной задаче на каждый компонент.

Восстановление также позволяет выбрать отдельные части. Это заменяет текущие данные; защищённый проект восстановить нельзя. Удаление составной копии удаляет все её найденные компоненты.

## Файловые стратегии

Стратегия — это именованный набор `include`- и `exclude`-glob-шаблонов. Если `include` пуст, в архив попадает весь проект кроме исключений. Сама `.docker-cli` и временные файлы задач в бэкап не попадают. Список файлов фиксируется до начала архивации.

В метаданных копии сохраняются код стратегии и фактические шаблоны. Поэтому позднейшее изменение стратегии не меняет состав уже созданного архива; при `tree:load --wipe` CLI предупредит о различии.

## Файловый бэкап из CLI

```bash
# Обычный архив
bin/docker-cli tree:dump --project=my-project --name=release-42 --compress=zstd

# Копия в общем хранилище со стратегией
bin/docker-cli tree:dump --project=my-project --location=nas --name=release-42 --strategy=source

# Тома не больше 2 GiB (либо ровно 4 тома)
bin/docker-cli tree:dump --chunk-size=2G
bin/docker-cli tree:dump --chunk-count=4
```

Поддержаны `gzip`, `bzip2`, `xz`, `zstd`, `lz4` и `zip`. `--chunk-size` принимает единицы `K`, `M` и `G`, в том числе дробные значения. `--chunk-size` и `--chunk-count` взаимоисключающие.

```bash
# Восстановить по имени; --force разрешает перезапись
bin/docker-cli tree:load --name=release-42 --force
bin/docker-cli tree:load --location=nas --name=release-42 --force

# Полностью очистить проект, сохранив .docker-cli, и восстановить копию
bin/docker-cli tree:load --name=release-42 --wipe

bin/docker-cli tree:backup-delete --location=nas release-42
```

`tree:load` проверяет `docker-cli.json`, принадлежность копии проекту и целостность всех томов. При `--wipe` удаляется всё содержимое проекта, кроме `.docker-cli`, а затем распаковывается архив.

## Базы данных из CLI

```bash
bin/docker-cli mysql:dump --project=my-project --location=nas --name=release-42 --threads=8
bin/docker-cli postgres:dump --project=my-project --location=nas --name=release-42 --jobs=8

bin/docker-cli mysql:load --project=my-project --location=nas --name=release-42 --threads=8 --force
bin/docker-cli postgres:load --project=my-project --location=nas --name=release-42 --jobs=8

# Команды удаления запускаются из корня проекта
bin/docker-cli mysql:backup-delete --location=nas release-42
bin/docker-cli postgres:backup-delete --location=nas release-42
```

Дамп MySQL создаётся `mydumper` и загружается `myloader`; PostgreSQL использует directory-формат `pg_dump`/`pg_restore`. Оба формата поддерживают параллельную работу и метаданные для проверки проекта при восстановлении.

::: warning
Восстановление базы заменяет её текущее содержимое. Перед запуском проверьте проект, имя копии и расположение. MySQL дополнительно требует `--force`.
:::
