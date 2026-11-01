# DBTrail

DBTrail доступен по адресу `https://dbtrail.<BASE_HOST>` и отслеживает системный MySQL и все выделенные инстансы
MySQL. PostgreSQL к DBTrail не подключается.

Для источников включены binary log, формат `ROW`, полные образы строк, полные метаданные колонок и запись исходного
SQL-запроса в row events. Благодаря этому DBTrail обнаруживает расхождение schema snapshot и заполняет `query_text`
для новых событий. Настройки не восстанавливают метаданные и SQL-текст событий, уже записанных в binary log.

Команда `config:seed` создаёт пользователя репликации, пароль индексной базы и внутренний API-токен синхронизации.
Этот токен не нужен для входа в Web UI.

## Выделенные MySQL

Сервис `dbtrail-sync` раз в минуту сверяет полный список контейнеров выделенных MySQL с реестром DBTrail. Новый
инстанс получает пользователя репликации, добавляется в DBTrail и запускается на отслеживание. Удалённый инстанс
останавливается и удаляется из реестра. Полная сверка также восстанавливает изменения, пропущенные во время
временной недоступности DBTrail или синхронизатора.

Остановленный, но не удалённый контейнер остаётся в реестре. После запуска синхронизатор снова проверит его и при
необходимости возобновит отслеживание.

## Таблицы без primary key

DBTrail исключает из snapshot таблицы без явного primary key и таблицы не на InnoDB. Остальные таблицы продолжают
отслеживаться без изменения схемы, но история исключённых таблиц недоступна.

После добавления primary key или перевода таблицы на InnoDB DBTrail обрабатывает DDL из binary log, автоматически
создаёт новый schema snapshot и начинает отслеживать последующие изменения таблицы. Старые изменения, пропущенные
до исправления схемы, в индексе не появятся.

Если таблица не появилась автоматически, откройте соответствующий источник в DBTrail и выполните действие
**Refresh schema snapshot**. DBTrail создаст snapshot и перезапустит stream этого источника.

## Высокая нагрузка MySQL и Btrfs

После длительной остановки DBTrail может догонять накопившиеся binary log. Если в них много `CREATE TABLE`,
`ALTER TABLE` и других DDL-событий, DBTrail создаёт новый schema snapshot после каждого изменения схемы. На окружении
с тысячами таблиц это может надолго загрузить исходный `docker-cli-mysql` чтением метаданных и
`docker-cli-dbtrail-index` записью снимков.

На хосте с Btrfs одновременно может потреблять CPU процесс `btrfs-cleaner`. Это следствие интенсивной записи в
Docker volume индексной базы, а не отдельная нагрузка со стороны проектов. Проверить контейнеры и прогресс можно
командами:

```bash
docker stats --no-stream docker-cli-dbtrail docker-cli-dbtrail-index docker-cli-mysql
docker logs --tail 100 docker-cli-dbtrail
```

В журнале на обработку накопленной истории указывают сообщения `gap detected`, `replaying missed events` и
повторяющиеся `DDL detected — taking auto-snapshot`. Пока DBTrail догоняет binary log, нагрузка возможна даже при
отсутствии пользовательских запросов.

### Временное отключение и удаление индекса

Если DBTrail пока не нужен, сначала задайте в `~/.config/docker-cli/compose/system/.env`:

```dotenv
COMPOSE_ENABLE_DBTRAIL=0
```

Затем пересоздайте системный стек без профиля DBTrail и убедитесь, что его контейнеры удалены:

```bash
docker-cli system:restart
docker ps -a --filter name=docker-cli-dbtrail
```

После этого можно удалить только индекс и накопленную историю DBTrail:

```bash
docker volume rm docker-cli_dbtrail-index-data
```

Удаление этого volume необратимо, но не затрагивает системный MySQL и базы проектов. Для полного удаления
служебного состояния DBTrail можно также удалить небольшие volumes:

```bash
docker volume rm docker-cli_dbtrail-state docker-cli_dbtrail-mysql-socket
```

Если Docker сообщает, что volume используется, найдите оставшийся контейнер перед удалением:

```bash
docker ps -a --filter volume=docker-cli_dbtrail-index-data
```

Не используйте для этой задачи `docker volume prune`: команда может удалить другие неиспользуемые volumes.

## Обновление существующей установки

```bash
docker-cli system:stop
docker-cli config:init --update --migrate --force
docker-cli config:seed --yes
docker-cli system:start
```
