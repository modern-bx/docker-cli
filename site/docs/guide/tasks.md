# Пользовательские задачи

Задачи позволяют хранить переиспользуемые Bash-сценарии и описание их интерфейса в YAML. Docker CLI ищет файлы с расширением `.yaml` и `.yml` рекурсивно во всех подпапках `~/.config/docker-cli/tasks`. Директория создаётся автоматически при первом обращении к задачам.

## Формат файла

```yaml
meta:
  schema: task
  version: 0.1
task:
  name: Демонстрационная задача
  code: demo.do-something
  type: shell
  context: project
  tags: [demo, maintenance]
  description: |
    Многострочное описание задачи.
    Оно отображается командой task:list.
  parameters:
    message:
      name: Сообщение
      type: string
      required: true
      tags: [text, input]
      description: |
        Текст, который будет напечатан.
        Описание параметра может быть многострочным.
    count:
      name: Количество
      type: integer
      min: 1
      max: 10
      required: true
    format:
      name: Формат
      type: list
      required: false
      items:
        - { name: Обычный, value: plain }
        - { name: JSON, value: json }
  return:
    type: integer
    min: 0
    max: 255
    required: false
  action: |
    echo "{{ message }} $count $format"
```

Обязательные поля задачи: `name`, `code`, `type` и `action`. Сейчас единственный поддерживаемый тип задачи — `shell`. Поля `description`, `context` и `tags` опциональны. Теги задачи и параметров — списки символьных кодов на латинице; допускаются также цифры, `.`, `_` и `-` после первой буквы. Опциональное поле параметра `strict-types: true` запрещает очереди автоматически преобразовывать целое значение строкового параметра в строку.

Параметры поддерживают типы `string`, `integer` и `list`. Для `integer` можно задать `min` и `max`. Для `list` можно задать варианты `items` с полями `name` и `value`; само поле `items` опционально, поскольку в дальнейшем варианты смогут поступать из других источников на основании тегов. Если `items` отсутствует или пуст, текущее выполнение принимает любое переданное строковое значение списка. Поля параметра `name`, `description`, `required` и `tags` опциональны.

В полном выводе `task:list` варианты списка показываются отдельным от ограничений многострочным блоком в формате `value: name`. В режиме `--short` этот блок скрыт.

## Подстановка и окружение

В `action` значение параметра можно подставить конструкцией `{{ parameter-code }}`. Значения экранируются перед вставкой в Bash. Все переданные параметры также доступны как переменные окружения; дефисы в их кодах заменяются подчёркиваниями (`my-param` → `$my_param`). Скомпилированный скрипт исполняется с `set -Eeuo pipefail`.

Если `context: project`, необходимо передать `--project`; рабочей директорией станет `document_root` зарегистрированного проекта. При отсутствующем или falsy `context` используется `/tmp/.docker-cli`, которая создаётся автоматически.

## Просмотр и запуск

Полный список с описаниями и метаданными параметров:

```bash
bin/docker-cli task:list
```

Только краткие сигнатуры или только выбранные задачи:

```bash
bin/docker-cli task:list --short
bin/docker-cli task:list --task=demo.do-something,project.cleanup
```

Аргументы запуска передаются позиционно в порядке спеки либо как `name=value`:

```bash
bin/docker-cli task:run demo.do-something 'message=Hello world' count=2 format=plain
bin/docker-cli task:run --project=my-project demo.do-something 'Hello world' 2 plain
```

По умолчанию временный Bash-файл удаляется после выполнения. Опция `--no-delete` сохраняет его и выводит путь. Код завершения `task:run` совпадает с кодом завершения Bash-скрипта.
