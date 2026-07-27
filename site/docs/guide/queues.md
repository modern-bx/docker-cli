# Очереди

Очереди хранятся в `~/.config/docker-cli/queue/<queue-code>`. При первом запуске `queue:step` автоматически создаются каталоги `10-pending`, `20-active`, `30-success`, `40-failure` и `50-error`. Сейчас очередь по умолчанию имеет код `default`.

Имя элемента состоит из timestamp в микросекундах, трёхзначного счётчика и кода, например `1720000000000000.000.demo.do-something.yaml`. Сортировка имён определяет порядок обработки.

```yaml
meta:
  schema: queue-item
  version: 0.1
task:
  commands:
    - code: demo.do-something
      project: my-project
      arguments:
        message:
          value: Hello
        count:
          value: 3
```

Спеки команд загружаются рекурсивно из `~/.config/docker-cli/commands` в формате пользовательских задач. Каждая запись `arguments` содержит поле `value`. Для команд с `context: project` обязательно поле `project`.

`queue:step` удерживает неблокирующую эксклюзивную блокировку на всё время шага. Элемент сначала переносится в `20-active`. Ошибки YAML и все найденные расхождения со спекой отправляют его в `50-error`; ненулевой код команды останавливает цепочку и отправляет элемент в `40-failure`; полностью выполненная цепочка попадает в `30-success`.

Каждое событие дописывается в `trace` самого YAML-файла и в общий журнал `~/.config/docker-cli/logs/queue/<queue-code>.log`. У нового pending-элемента блок `trace` может отсутствовать.
