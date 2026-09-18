# CLI: интерфейс, automation contract и UX

## 1. CLI — публичный API

Имя команды, option/argument, exit code, stdout/stderr и machine-readable output образуют public contract. Изменение CLI должно рассматриваться так же внимательно, как изменение HTTP API.

## 2. Имена команд

- lower-case, слова через `:` для namespaces: `cache:clear`, `config:check`;
- command name описывает действие;
- не делать aliases без реальной migration/ergonomic причины;
- опасное действие должно быть очевидно из имени и help.

## 3. Arguments vs options

Argument — основной позиционный operand. Option — modifier/configuration.

Предпочитать:

```text
acme-cli export input.json --format=json --output=result.json
```

вместо длинной последовательности positional values, значение которых невозможно помнить.

Boolean options: `--force`, `--dry-run`, `--no-color`. Значения: `--timeout=30` или стандартная Console форма.

## 4. Help

Каждая команда MUST иметь точные `description`, argument/option descriptions и meaningful help для сложного поведения. Текст на русском.

Help должен отвечать:

- что делает команда;
- что читает/изменяет;
- значения по умолчанию;
- side effects;
- требования к environment;
- что означает `--force`/`--dry-run`;
- примеры, если syntax нетривиален.

## 5. Output streams

- stdout: нормальный результат, который можно pipe/redirect;
- stderr: diagnostics/errors/progress, если это не часть data result;
- не смешивать machine output и декоративный human output;
- для pipeline-friendly режима предусматривать `--format=json`/аналог, когда сценарий реально нужен.

Structured output MUST иметь стабильную schema и не содержать ANSI decoration.

## 6. Exit codes

- `0` — success;
- non-zero — failure/invalid usage;
- использовать `Command::SUCCESS`, `FAILURE`, `INVALID` для базовых случаев;
- отдельные codes вводить только как документированный automation contract;
- нельзя вернуть `0` после частичного failure, если caller должен считать операцию неуспешной.

## 7. Interactive behavior

CI/automation MUST иметь путь без prompt. Любая команда, которая может спросить подтверждение, должна корректно работать с `--no-interaction` и иметь ясное поведение без TTY.

Не спрашивать то, что можно безопасно определить автоматически.

Destructive operation:

- default безопасный;
- interactive confirmation MAY быть дополнительной защитой;
- automation должен использовать явный `--force`/confirmation flag;
- `--dry-run` SHOULD быть у массовых destructive actions.

## 8. Color/TTY

Не полагаться на color для передачи смысла. ANSI control codes не должны попадать в redirected structured output. Symfony Console умеет учитывать decoration/TTY — использовать framework behavior.

## 9. Progress

Progress bar уместен только для длительной bounded operation. Для unbounded server/process progress bar не нужен. В verbose mode лучше структурированные диагностические этапы.

## 10. Verbosity

Использовать стандартные Symfony уровни `-q`, `-v`, `-vv`, `-vvv` вместо собственного `--debug`, если нет отдельной семантики.

- normal: полезный итог;
- verbose: основные шаги;
- very verbose/debug: technical diagnostics без secrets.

## 11. Errors

Human error должен сказать, что не так и что пользователь может сделать дальше, если действие очевидно. Не печатать raw stack trace в обычном режиме из собственного handler. Framework/debug mode MAY показывать техническую трассировку.

Все человекочитаемые сообщения проекта — на русском.

## 12. Configuration precedence

Если приложение получает settings из нескольких источников, precedence MUST быть документирован и детерминирован. Рекомендуемый порядок:

1. explicit CLI option;
2. environment variable;
3. project/user config;
4. built-in default.

Не читать env в случайных service methods; конфигурация нормализуется один раз в bootstrap/application layer.

## 13. Paths

- `-` MAY означать stdin/stdout только если это явно документировано;
- относительные paths интерпретировать относительно текущего working directory пользователя, если CLI contract не определяет иначе;
- internal bundled asset paths не зависят от CWD;
- display path и canonical path не смешивать.

## 14. Signals

Long-running commands SHOULD корректно завершаться по SIGINT/SIGTERM, закрывая sockets/streams и оставляя данные в согласованном состоянии. Не ловить signal только для того, чтобы игнорировать shutdown.

## 15. Automation stability

Нельзя без major/breaking decision:

- менять смысл существующего option;
- менять default опасным образом;
- переносить machine result со stdout в stderr;
- менять JSON field names;
- превращать success в interactive prompt;
- вводить обязательный TTY.

## 16. Checklist

- [ ] Команда имеет ясное имя/description/help.
- [ ] Thin command: logic вынесена в service.
- [ ] Exit code соответствует результату.
- [ ] stdout/stderr разделены осознанно.
- [ ] Automation работает без TTY.
- [ ] Human text на русском.
- [ ] Secrets не выводятся.
- [ ] Destructive behavior имеет безопасный default.

## Источники

- Symfony Console: https://symfony.com/doc/current/components/console.html
- Console input/output: https://symfony.com/doc/current/console.html
