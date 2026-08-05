# Справочник команд

## Конфигурация

### `bin/docker-cli config:init`

Создаёт системную конфигурацию в `~/.config/docker-cli/compose/system` и добавляет `.env` / `compose.yaml`, если они отсутствуют.

Опции:

- `--update` — перезаписывает статические файлы конфигурации из шаблонов после подтверждения.
- `--force` — отключает интерактивное подтверждение для `--update`.
- `--migrate` — добавляет отсутствующие параметры в редактируемые файлы.
- `--rebuild` — пересобирает значения, зависящие от зарегистрированных проектов.
- `--examples` — копирует примеры пользовательских файлов из ресурсов, включая примеры хуков команд, с перезаписью без подтверждения.

Пример:

```bash
bin/docker-cli config:init --update --force --migrate --rebuild
bin/docker-cli config:init --examples
```

### `bin/docker-cli config:seed`

Заполняет пустые сидируемые значения в `.env`: пароли MySQL/PostgreSQL.

```bash
bin/docker-cli config:seed
```

Автоматический режим без интерактивного подтверждения:

```bash
bin/docker-cli config:seed --yes
```

## Проекты

### `bin/docker-cli shell:bash`

Открывает интерактивный Bash в контейнере PHP-FPM выбранной для проекта версии от имени пользователя
`docker-cli`. Короткий алиас команды — `bash`.

```bash
bin/docker-cli shell:bash
bin/docker-cli bash
bin/docker-cli bash --project=my-project
```

При запуске из зарегистрированного проекта рабочей директорией становится корень
проекта. Опция `--project` позволяет явно выбрать зарегистрированный проект при
запуске из другой директории. При запуске вне проекта и без `--project` оболочка
открывается в `/home/docker-cli`.

Стандартные файлы запуска Bash (`.bashrc`, `.profile`, `.bash_profile` и другие)
не загружаются. Если существует `/home/docker-cli/.docker-cli.profile`, Bash
использует только его как пользовательский файл конфигурации.

### `bin/docker-cli shell:run [--project=project] <args>...`

Выполняет команду в контейнере PHP-FPM выбранной для проекта версии от имени пользователя `docker-cli`.
Короткий алиас — `run`; это также команда `docker-cli` по умолчанию. Рабочая
директория выбирается по тем же правилам, что и у `shell:bash`.

```bash
bin/docker-cli shell:run 'php -v'
bin/docker-cli run --project=my-project 'composer install'
bin/docker-cli run 'echo "$USER" && ls -la | head'
bin/docker-cli -- 'echo "$USER"'
```

Команда выполняется через Bash, поэтому поддерживает переменные окружения, пайпы,
перенаправления и цепочки команд. Чтобы внешний shell не обработал выражение раньше
контейнера, всю команду рекомендуется передавать одним аргументом в одинарных кавычках.
Так как `shell:run` является командой по умолчанию, её имя можно опустить, отделив
выражение от глобальных опций `docker-cli` с помощью `--`.
Перед выполнением стандартные профили Bash не загружаются, но при наличии явно
подключается `/home/docker-cli/.docker-cli.profile`.

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

Для команды поддерживаются исполняемые хуки из каталогов
`~/.config/docker-cli/actions/hooks/commands/<код-команды>.before` и
`~/.config/docker-cli/actions/hooks/commands/<код-команды>.after`, где двоеточие в коде команды заменяется точкой, например `project.up.before`.
Сейчас хуки команд включены для `project:up`, `project:clone`, `project:disable`, `project:down`, `project:enable`, `project:update` и `project:wipe`.
Файлы, имена которых начинаются с точки, игнорируются. Остальные файлы запускаются
в алфавитном порядке из текущего рабочего каталога команды. Каждый хук получает
аргументы `hook:command`, `<код-команды>:before` или `<код-команды>:after`, а затем все
исходные аргументы команды в неизменном порядке. Ненулевой код завершения хука
останавливает выполнение цепочки и возвращается как код завершения команды. Результат каждого запуска пишется в `~/.config/docker-cli/journal/hooks/default.jsonl`: код возврата `0` и вывод в `stdout` получают уровень `info`, ненулевой код возврата и вывод в `stderr` — уровень `error`; уровни `debug` и `warning` зарезервированы в фильтрах журнала, но пока не используются. Примеры хуков хранятся в `resources/actions/hooks` и копируются только командой `config:init --examples`; их имена начинаются с точки, поэтому после копирования они остаются отключенными, пока пользователь не переименует их. Подробности, формат JSONL-журнала и работа в панели описаны в [руководстве по хукам](/guide/hooks).

`--no-restart` пропускает перезапуск общего пула проектных сервисов.

### `bin/docker-cli project:list`

Выводит кодовые имена всех зарегистрированных проектов, по одному на строку:

```bash
bin/docker-cli project:list
```

### `bin/docker-cli project:clone`

Копирует файлы зарегистрированного проекта, регистрирует целевой проект и по
умолчанию клонирует его MySQL- и PostgreSQL-базы. Исходный проект задаётся через
`--from` или определяется по текущей директории. `--to` принимает код проекта либо
явный путь; без явного пути каталог создаётся в расположении из настроек панели.

```bash
bin/docker-cli project:clone --from=my-project --to=my-project-copy
bin/docker-cli project:clone --to=/home/user/projects/my-project-copy
bin/docker-cli project:clone --to=my-project-copy --location=work
bin/docker-cli project:clone --to=my-project-copy --here
```

`--exclude` принимает разделённые запятыми glob-шаблоны файлов, которые не нужно
копировать. `--skip-db` отключает клонирование баз, а `--dbms=mysql,postgres`
ограничивает список клонируемых СУБД. Опции `--here` и `--location`, как и
`--skip-db` и `--dbms`, взаимоисключающие. `--force` очищает уже существующую
целевую директорию, поэтому его следует использовать только после проверки пути.
После успешного клонирования команда пересобирает конфигурацию хостов, обновляет
DNS-алиасы через Traefik и Dnsdock и выполняет reload OpenResty, поэтому новый
проект сразу открывается на собственном хосте.

### `bin/docker-cli project:update [--name] [--language] [--framework] [--language-version]`

Изменяет имя, язык, фреймворк и версию PHP проекта. `--language-version` принимает `8.2`, `8.3`, `8.4` или `8.5`. Команду нужно запускать из директории
зарегистрированного проекта. Если язык или фреймворк изменились, команда пересобирает
конфигурацию OpenResty и перезагружает связанные сервисы. Вызов без опций выводит
предупреждение и завершается успешно.

```bash
bin/docker-cli project:update --name=new-project --language=php --framework=symfony --language-version=8.4
```

### `bin/docker-cli project:disable [project]` / `bin/docker-cli project:enable [project]`

Отключает или включает web-конфигурацию зарегистрированного проекта, после чего
пересобирает конфигурацию OpenResty и перезапускает связанные системные сервисы.
Проект можно указать явно или определить по текущей директории.

```bash
bin/docker-cli project:disable my-project
bin/docker-cli project:enable my-project
```

### `bin/docker-cli project:down`

Удаляет регистрацию текущего проекта.

```bash
bin/docker-cli project:down
bin/docker-cli project:down --drop --force
```

Опция:

```bash
bin/docker-cli project:down --no-restart
```

`--no-restart` пропускает перезапуск общего пула проектных сервисов.

`--drop` перед удалением регистрации удаляет базы данных и пользователей проекта
во всех поддерживаемых СУБД. Это необратимое действие требует одновременной опции
`--force`. Если удаление данных завершилось ошибкой, регистрация проекта сохраняется.

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

### `bin/docker-cli data:database-create [--user=<user1,user2>] [--dbms=<dbms>] <database>`

Создаёт базу в MySQL и PostgreSQL либо только в выбранной через `--dbms` СУБД.
Перечисленные через запятую пользователи при необходимости создаются и получают
полные права на новую базу. Для каждого пользователя генерируется новый случайный
24-символьный пароль из латинских букв в обоих регистрах и цифр; учетные данные
выводятся после успешного выполнения команды.

```bash
bin/docker-cli data:database-create --user=developer,reporter application
bin/docker-cli data:database-create --dbms=mysql cache
```

### `bin/docker-cli data:database-delete [--force] [--dbms=<dbms>] <database1,database2>`

Удаляет перечисленные базы. Без `--dbms` команда работает с обеими СУБД, без
`--force` запрашивает подтверждение. Для уже отсутствующих баз выводится предупреждение.

### `bin/docker-cli data:dbuser-create [--dbms=<dbms>] [--database=<database1,database2>] <user>`

Создаёт пользователя в одной или обеих СУБД. Если указаны базы, команда сначала
проверяет наличие всех баз во всех выбранных СУБД и только затем создаёт пользователя
и выдаёт права. Поэтому ошибка проверки не оставляет частично выданных прав. Новый
случайный 24-символьный пароль выводится после успешного выполнения команды.

### `bin/docker-cli data:dbuser-delete [--force] [--dbms=<dbms>] <user1,user2>`

Удаляет пользователей в одной или обеих СУБД. Требует подтверждения, если не передан
`--force`, и предупреждает об отсутствующих пользователях.

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

Подробное описание локальных и централизованных хранилищ, составных копий и файловых стратегий приведено в [руководстве по бэкапам](/guide/backups).

## Быстрые параллельные дампы MySQL

### `bin/docker-cli mysql:dump [--name=<имя> | --path=<путь>]`

Создаёт согласованный многопоточный дамп только MySQL-базы выбранного проекта через
контейнер `mydumper/mydumper:v1.0.3-1`. Проект выбирается через `--project` или по
текущей директории. По умолчанию несжатый дамп пишется в
`.docker-cli/backups/mysql/<проект>-<дата>`: отсутствие сжатия уменьшает нагрузку
на CPU и ускоряет последующую загрузку.

```bash
bin/docker-cli mysql:dump
bin/docker-cli mysql:dump --project=my-project --name=release-42
bin/docker-cli mysql:dump --project=my-project --threads=8 --path=/mnt/fast/backups/release-42
```

`--threads` (`-j`, по умолчанию `4`) управляет числом потоков mydumper. Каталог
назначения должен быть пустым. `--name` задаёт короткое имя внутри стандартного
каталога `.docker-cli/backups/mysql`, а `--path` — полный путь к каталогу конкретного
бэкапа. Эти опции взаимоисключающие. `--location=<код>` вместе с `--name` выбирает общее хранилище из настроек панели. MySQL продолжает обслуживать запросы во время
создания дампа.

### `bin/docker-cli mysql:backup-delete <backup>`

Безвозвратно удаляет каталог MySQL-бэкапа с указанным коротким именем из
`.docker-cli/backups/mysql` текущего проекта. Команда работает только из контекста
зарегистрированного проекта и не поддерживает переопределение проекта через
`--project`.

```bash
bin/docker-cli mysql:backup-delete my-project-20260728-120000
bin/docker-cli mysql:backup-delete --location=nas release-42
```

### `bin/docker-cli mysql:load <path> --force`

Полностью заменяет и многопоточно загружает только MySQL-базу выбранного проекта.
Другие базы общего системного MySQL не удаляются и сам сервер не останавливается.
`--threads` (`-j`) задаёт параллелизм myloader; индексы создаются после загрузки
данных всех таблиц, чтобы сократить время восстановления.

```bash
bin/docker-cli mysql:load --force .docker-cli/backups/mysql/my-project-20260728-120000
bin/docker-cli mysql:load --project=my-project --threads=8 --force /mnt/fast/backups/release-42
bin/docker-cli mysql:load --force --skip-checks /mnt/fast/backups/another-project
```

Команда требует явный `--force`, проверяет формат дампа и метаданные проектного
контекста. Опция `--skip-checks` отключает только проверку проекта и имени базы в
`docker-cli.json`: дамп загружается в MySQL-базу из `project.yaml` текущего проекта.
Проверка формата дампа mydumper при этом сохраняется. Это позволяет, например,
загрузить копию базы одного проекта в базу другого проекта для тестирования.

Опция `--disable-redo-log` может существенно ускорить большую загрузку,
но временно отключает InnoDB redo log глобально для системного MySQL. Её следует
использовать только когда параллельная работа с другими базами остановлена; при
аварии MySQL во время загрузки может потребоваться пересоздание экземпляра.

## Быстрые параллельные дампы PostgreSQL

### `bin/docker-cli postgres:dump [--name=<имя> | --path=<путь>]`

Создаёт directory-бэкап PostgreSQL-базы выбранного проекта с помощью параллельного
`pg_dump`. Проект выбирается через `--project` или по текущей директории. По
умолчанию новый каталог создаётся в `.docker-cli/backups/postgres` и получает имя
`<проект>-<дата>`.

```bash
bin/docker-cli postgres:dump
bin/docker-cli postgres:dump --project=my-project --name=release-42
bin/docker-cli postgres:dump --project=my-project --jobs=8 --path=/mnt/fast/backups/release-42
```

`--jobs` (`-j`, по умолчанию `4`) задаёт число параллельных процессов. `--name`
задаёт короткое имя внутри стандартного каталога `.docker-cli/backups/postgres`, а
`--path` — полный путь к каталогу конкретного бэкапа. Эти опции взаимоисключающие.
В бэкап записывается `docker-cli.json` с кодом проекта и именем базы для проверки
при восстановлении. `--location=<код>` вместе с `--name` выбирает общее хранилище из настроек панели.

### `bin/docker-cli postgres:load <path>`

Удаляет и заново создаёт PostgreSQL-базу выбранного проекта, затем параллельно
восстанавливает её из directory-бэкапа `postgres:dump`. Команда необратимо заменяет
текущие данные и не выполняется для защищённого проекта.

```bash
bin/docker-cli postgres:load .docker-cli/backups/postgres/my-project-20260731-120000
bin/docker-cli postgres:load --project=my-project --jobs=8 /mnt/fast/backups/my-project-20260731-120000
```

`--jobs` (`-j`, по умолчанию `4`) управляет параллелизмом `pg_restore`. Перед
загрузкой команда проверяет наличие `toc.dat`, а при наличии `docker-cli.json` —
соответствие проекта и базы данных.

### `bin/docker-cli postgres:backup-delete <backup>`

Безвозвратно удаляет каталог PostgreSQL-бэкапа текущего проекта. Для копии в общем хранилище передайте его код:

```bash
bin/docker-cli postgres:backup-delete my-project-20260731-120000
bin/docker-cli postgres:backup-delete --location=nas release-42
```

## Файловые бэкапы

### `bin/docker-cli tree:dump`

Создаёт tar- или ZIP-архив файлов проекта. Поддерживает `--project`, `--name`, `--location`, `--strategy`, `--compress`, а также взаимоисключающие `--chunk-size` и `--chunk-count` для многотомных архивов.

### `bin/docker-cli tree:load`

Восстанавливает файловую копию, выбранную через `--name` (при необходимости вместе с `--location`) или прямой `--path`. `--force` разрешает перезапись, `--wipe` предварительно очищает проект с сохранением `.docker-cli`.

### `bin/docker-cli tree:backup-delete <backup>`

Удаляет файловую копию текущего проекта; `--location` выбирает общее хранилище. Примеры и правила безопасности приведены в [руководстве по бэкапам](/guide/backups).

## Пользовательские задачи

Полная спецификация YAML и описание выполнения приведены в разделе [«Пользовательские задачи»](/guide/tasks).

### `bin/docker-cli task:list [--short] [--task=<code[,code2]>]`

Выводит найденные задачи, отсортированные по коду. Полный режим показывает код, название и описание задачи, краткую сигнатуру, теги задачи, а также код, название, тип, ограничения, теги и полное многострочное описание каждого параметра.

`--short` скрывает подробные сведения под сигнатурой. `--task` принимает один или несколько разделённых запятыми кодов и оставляет в результате только эти задачи:

```bash
bin/docker-cli task:list
bin/docker-cli task:list --short
bin/docker-cli task:list --task=demo.do-something,project.cleanup
```

### `bin/docker-cli task:run [--project=<project>] [--no-delete] <task-code> [task-args...]`

Проверяет интерфейс задачи, компилирует её `action` во временный строгий Bash-скрипт и возвращает код его завершения. Аргументы принимаются позиционно либо в виде `name=value`:

```bash
bin/docker-cli task:run demo.do-something message=hello count=2
bin/docker-cli task:run --project=my-project demo.do-something hello 2
```

`--project` обязателен для задач с `context: project`. `--no-delete` оставляет скомпилированный файл после выполнения и печатает его путь.

## Очереди

### `bin/docker-cli queue:item-delete [--queue=<queue-code>] <item>`

Удаляет элемент из выбранной очереди (`default` по умолчанию). Короткое имя принимается с расширением `.yaml` или без него. Элемент в статусе `20-active` удалить нельзя.

```bash
bin/docker-cli queue:item-delete 1720000000000000.000.demo.do-something
bin/docker-cli queue:item-delete --queue=notifications 1720000000000001.000.notify.yaml
```

### `bin/docker-cli queue:list [--queue=<queue-code>] [--status=<status>] [--short]`

Выводит таблицу элементов всех очередей с их статусами, задачами и журналами. `--queue` выбирает одну очередь, а `--status` принимает полный статус (`10-pending`), его номер (`10`) или символьный код (`pending`). С `--short` команда выводит только пути относительно `docker-cli/state/queue`.

```bash
bin/docker-cli queue:list
bin/docker-cli queue:list --queue=default --status=pending
bin/docker-cli queue:list --status=30 --short
```

### `bin/docker-cli queue:item-create [--queue=<queue-code>] --mode=task --task=<task-code> [--project=<project>] [task-args...]`

Проверяет аргументы по спеке задачи и создаёт в `10-pending` элемент ровно с одной задачей. Аргументы можно передавать позиционно или в виде `name=value`. Для задач с `context: project` требуется `--project`.

```bash
bin/docker-cli queue:item-create --mode=task --task=demo.do-something message=hello count=2
bin/docker-cli queue:item-create --queue=notifications --mode=task --task=project.cleanup --project=my-project
```

### `bin/docker-cli queue:step [--queue=<queue-code>]`

Атомарно забирает самый ранний YAML-файл из `10-pending`, проверяет его и выполняет перечисленные задачи по порядку. По умолчанию используется очередь `default`; параллельная обработка одной очереди блокируется. Успешные элементы перемещаются в `30-success`, завершившиеся ненулевым кодом — в `40-failure`, а некорректные — в `50-error`.

```bash
bin/docker-cli queue:step
bin/docker-cli queue:step --queue=default
```

Структура каталогов, формат элемента и журналирование описаны в разделе [«Очереди»](/guide/queues).

### `bin/docker-cli queue:pause [--queue=<queue-code>]`

Создаёт флаг `.pause` и приостанавливает выборку новых элементов. Уже выполняющийся элемент продолжает работу.

```bash
bin/docker-cli queue:pause
bin/docker-cli queue:pause --queue=notifications
```

### `bin/docker-cli queue:resume [--queue=<queue-code>]`

Удаляет флаг `.pause` и возобновляет выборку новых элементов.

```bash
bin/docker-cli queue:resume
bin/docker-cli queue:resume --queue=notifications
```

### `bin/docker-cli queue:start [--queue=<queue-code>]`

Запускает постоянный обработчик очереди: команда последовательно обрабатывает новые элементы и ожидает следующие. Если существует флаг `.pause`, обработчик остаётся запущенным, но не выбирает новые элементы и повторяет проверку каждую секунду. По умолчанию используется очередь `default`. Служебные сообщения и ошибки элементов не дублируются в `stdout`/`stderr`, поскольку они сохраняются в элементе и общем журнале очереди; в консоль попадают только критические ошибки самого обработчика. Собственный вывод выполняемых задач, например созданный через `echo`, остаётся видимым в `stdout`/`stderr`.

```bash
bin/docker-cli queue:start
bin/docker-cli queue:start --queue=notifications
```

Опция `-d` (или `--daemon`) создаёт, включает и запускает systemd-сервис с именем `docker-cli.queue.<queue-code>`. Опция `--user` задаёт пользователя сервиса, а `--path` позволяет явно указать исполняемый файл:

```bash
sudo bin/docker-cli queue:start -d --queue=notifications
sudo bin/docker-cli queue:start -d --queue=notifications --user=www-data --path=/usr/local/bin/docker-cli
systemctl status docker-cli.queue.notifications
```

Каталог бинарника из `--path` добавляется в `PATH` сервиса, чтобы задачи могли запускать команду `docker-cli` по имени.

### `bin/docker-cli queue:stop [--queue=<queue-code>]`

Останавливает и отключает systemd-сервис выбранной очереди, удаляет unit-файл и обновляет конфигурацию systemd. По умолчанию используется очередь `default`. Если сервис не установлен, команда завершается с ошибкой.

```bash
sudo bin/docker-cli queue:stop
sudo bin/docker-cli queue:stop --queue=notifications
```

## Административная панель

### `bin/docker-cli panel:up [-d]`

Запускает HTTP-сервер панели в текущем процессе. Опция `--port` переопределяет порт из системного `.env`.

С опцией `-d` (или `--daemon`) команда создаёт системный сервис `docker-cli.panel`, записывает unit-файл в `/etc/systemd/system/docker-cli.panel.service`, включает его и сразу запускает. Сервис использует тот исполняемый файл `docker-cli`, которым была вызвана команда регистрации. Опция `--user` задаёт пользователя сервиса, а `--path` позволяет явно указать исполняемый файл:

Регистрация и удаление сервиса не требуют конфигурации `docker-cli` у пользователя `root`, поэтому обе команды можно выполнять через `sudo`. Конфигурация проверяется уже при запуске панели от пользователя, указанного в `--user`.

```bash
sudo bin/docker-cli panel:up -d
sudo bin/docker-cli panel:up -d --user=www-data --path=/usr/local/bin/docker-cli
systemctl status docker-cli.panel
```

### `bin/docker-cli panel:down`

Останавливает и отключает сервис `docker-cli.panel`, удаляет его unit-файл и обновляет конфигурацию systemd. Если сервис не установлен, команда завершается с ошибкой.

```bash
sudo bin/docker-cli panel:down
```

### `bin/docker-cli panel:user-create`

Интерактивно запрашивает логин в формате email и пароль, затем добавляет пользователя панели. Пароль сохраняется в виде солёного хеша в `~/.config/docker-cli/state/panel/users.yaml`.

```bash
bin/docker-cli panel:user-create
```

### `bin/docker-cli panel:user-delete <логин>`

Удаляет пользователя панели, если пользователь с таким email существует.

```bash
bin/docker-cli panel:user-delete admin@example.com
```

### `bin/docker-cli panel:password-rotate <логины>`

Генерирует и выводит новые пароли для перечисленных через запятую пользователей.
После смены пароля активные токены соответствующего пользователя отзываются.

```bash
bin/docker-cli panel:password-rotate admin@example.com,developer@example.com
```

### `bin/docker-cli panel:token-revoke <логины>`

Отзывает все активные токены сессий перечисленных через запятую пользователей, не
меняя их пароли.

```bash
bin/docker-cli panel:token-revoke admin@example.com,developer@example.com
```

Случайная соль `PANEL_PASSWORD_SALT` создаётся в системном `.env` командой `config:init`.
Там же создаётся отдельный секрет `PANEL_JWT_SECRET`, которым подписываются токены авторизации панели. JWT действует 10 минут и обновляется при каждом успешном запросе проверки сессии.

Интерфейс панели реализован как Svelte SPA на Skeleton. Сборка находится в `resources/panel/dist`, а исходный код — в `resources/panel/src`. Для пересборки интерфейса выполните:

```bash
composer build-panel
```

Команда `composer build` последовательно собирает панель и PHAR. Важно: сборка не заменяет ранее установленный глобальный исполняемый файл `docker-cli`. После сборки запускайте `build/docker-cli.phar` либо установите этот файл вместо старой версии.

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

Запускает JavaScript-сценарий из `~/.config/docker-cli/actions/playwright/scripts`; расширение `.js` можно опустить. `--browser` выбирает `chromium`, `firefox` или `webkit`, а `--show` открывает управляемый браузер через локальный noVNC viewer:

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
