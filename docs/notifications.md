# Уведомления

Уведомления хранятся в `$HOME/.config/docker-cli/notifications`. Активные YAML-файлы находятся в `current`, архивные — в `archive`. Имя файла имеет формат `timestamp.counter.code.yaml`, аналогичный элементам очереди.

```yaml
time: '2026-07-29T12:34:56.123456+00:00'
origin: core.project.wipe
class: task
level: info
message: |
  Файлы проекта **example** успешно удалены.
```

Обязательные поля: дата и время `time`, строковые источник `origin` и класс источника `class`, уровень `level` (`info`, `warn`, `error` или `debug`) и многострочный Markdown-текст `message`.
