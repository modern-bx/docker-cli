# PHP 8.4: код-стайл и инженерные правила

## 1. Область действия

Правила относятся ко всему PHP-коду проекта. Базовый формат — PSR-12, дополненный строгой типизацией, статическим анализом, Slevomat и практиками PHP 8.4.

MUST:

```php
<?php

declare(strict_types=1);

namespace Acme\Cli\Service;
```

Новый код проектируется под PHP 8.4, а не под «наименьший общий знаменатель» старых версий.

## 2. Формат и структура

- 4 пробела; tab запрещён.
- PSR-2/PSR-12 имеют приоритет для расположения фигурных скобок классов, методов и функций.
- Стиль K&R применяется только там, где он не конфликтует с PSR-2/PSR-12: для управляющих конструкций и
  анонимных функций открывающая скобка остаётся на той же строке.
- Максимальная длина строки — 120 символов; более длинные выражения MUST быть аккуратно перенесены.
- LF, UTF-8, final newline.
- Один основной class/interface/trait/enum на файл.
- Namespace соответствует PSR-4 пути.
- Imports явные, отсортированные, без group use.
- Fully-qualified имена внутри business code не использовать вместо нормального `use`, кроме действительно локальных специальных случаев.
- Фигурные скобки обязательны даже для однострочного `if/foreach/while`.
- Не выравнивать `=` пробелами вручную.

## 3. Типы

Типизация обязательна максимально близко к фактическому contract:

- параметры и return types MUST быть типизированы;
- properties MUST иметь тип;
- `mixed` — крайняя integration boundary, а не универсальный escape hatch;
- array без известной структуры SHOULD заменяться DTO/value object или документироваться precise array shape;
- nullable type означает реальную семантику отсутствия значения;
- union type не должен маскировать смешение разных обязанностей;
- `never` использовать для методов, которые гарантированно завершают выполнение process/throw;
- `void` не использовать вместо meaningful result там, где вызывающему коду нужно различать outcome.

PHPDoc не должен повторять native types без добавочной информации.

## 4. Immutable-by-default

- Сервисы без mutable state SHOULD быть `readonly`/`final readonly`, где это уместно.
- DTO/value object SHOULD быть immutable.
- Constructor property promotion использовать для простых зависимостей/значений.
- Не делать property public только ради удобства теста.

Пример:

```php
final readonly class ExportRequest {
    public function __construct(
        public string $format,
        public string $destination,
    ) {
    }
}
```

## 5. Классы, интерфейсы, наследование

- Classes по умолчанию `final`.
- Наследование — только при реальной polymorphic relationship/extension contract.
- Composition предпочтительнее inheritance.
- Interface вводится на архитектурной границе, при нескольких реализациях или для test seam; не создавать `FooInterface` для каждого `Foo` механически.
- Trait использовать редко: shared stateful behavior через trait часто скрывает coupling.

## 6. Имена

- Class/interface/enum: `PascalCase`.
- Methods/properties/local variables: `camelCase`.
- Constants: `UPPER_SNAKE_CASE`.
- Boolean: `is/has/can/should/supports`.
- Collection в plural.
- Не использовать vague `data`, `info`, `obj`, `tmp`, `manager`, `helper`, если можно назвать domain intent.
- Service name описывает capability, а не технический суффикс.

## 7. Условия и guard clauses

Предпочитать ранний выход вместо глубокой вложенности:

```php
if ($path === '') {
    throw new InvalidArgumentException('Путь не должен быть пустым.');
}

if (!is_readable($path)) {
    throw new RuntimeException('Файл недоступен для чтения.');
}
```

Не использовать truthiness, если значения `0`, `'0'`, `''`, `null`, `false` имеют различную семантику.

## 8. Исключения и ошибки

- Исключение представляет exceptional failure, а не обычную ветку выбора.
- На boundary выбирать meaningful exception type.
- Нельзя `catch (Throwable)` и молча продолжать.
- При wrapping exception сохранять `$previous`.
- Сообщение исключения MUST быть на русском, если оно человекочитаемое и не локализуется.
- Секреты, tokens, passwords и большие payload в exception/log не помещать.
- Application/domain exception MAY иметь machine-readable code/property отдельно от русского сообщения.

```php
throw new RuntimeException(
    sprintf('Не удалось открыть файл конфигурации: %s', $path),
    previous: $exception,
);
```

## 9. Результаты вместо исключений

Для ожидаемых validation outcomes MAY использоваться value object/result type. Не создавать самодельный `Result` без потребности. В CLI boundary ожидаемая ошибка пользователя обычно преобразуется в понятное сообщение + non-zero exit code.

## 10. Arrays и collections

- Не использовать массив как бесконечно расширяемый DTO.
- List должен быть list: `list<T>` в PHPDoc.
- Map документировать `array<string, T>`.
- Проверять внешние arrays до доступа к ключам.
- `array_filter` помнить о preservation keys и default truthiness.
- Не делать цепочки array functions, если простой `foreach` читается лучше и не создаёт лишние allocations.

## 11. Enum

Native enum SHOULD использоваться для конечного стабильного набора состояний. Для wire protocol хорошо подходит backed enum, если значения действительно являются частью стабильного contract. Не ловить неизвестное external value через `from()` без обработки; использовать `tryFrom()` на недоверенной границе.

## 12. Strings

- Интерполяция/`sprintf` — по читаемости.
- Multibyte semantics учитывать явно; `strlen` — bytes.
- Для identifiers/protocol strings обычно ASCII semantics.
- Dynamic shell command через string concatenation запрещён.
- User-visible terminal text — через Console Output/SymfonyStyle, а не случайный `echo` из service layer.

## 13. Filesystem

- Нельзя предполагать, что CWD == project directory.
- Paths строить от explicit base path / config / executable context.
- Проверять результат I/O operations.
- Использовать atomic write pattern для критичных файлов: temp file → flush/fsync при необходимости → rename.
- Не писать внутрь PHAR и рядом с PHAR без явно заданного destination.
- Symlink/path traversal учитывать на недоверенных путях.

## 14. Process execution

Если приложение запускает внешние процессы:

- аргументы MUST не собираться небезопасной конкатенацией;
- предпочтителен `symfony/process`, если сложность оправдывает dependency;
- exit code, stdout/stderr обрабатываются раздельно;
- timeout обязателен для потенциально зависающих process;
- environment передаётся минимально необходимый;
- секреты не выводятся в diagnostic command line.

## 15. Date/time

- `DateTimeImmutable` предпочтительнее mutable `DateTime`.
- Timezone должна быть явной на integration boundary.
- Для storage/wire format предпочитать ISO 8601/RFC 3339.
- Duration не смешивать с timestamp.
- Не использовать local wall-clock для monotonic timeout measurement.

## 16. Security

- Всё внешнее input недоверенное: CLI args, env, config, HTTP, filesystem, IPC.
- Validate → normalize → use.
- Не использовать `eval`.
- Unserialize недоверенных данных запрещён.
- Dynamic include/require из user input запрещён.
- Не отключать TLS verification.
- Secret MUST приходить извне source tree и не попадать в log.
- При создании files с secret data устанавливать restrictive permissions.

## 17. Dependency Injection

Constructor injection — default. Service MUST явно объявлять свои зависимости.

Запрещено:

- `ContainerInterface` в domain/application service;
- `ServiceLocator::get()`-подобные вызовы внутри business logic;
- глобальные mutable registries;
- скрытое чтение env в глубине service method.

Env/config читается в bootstrap/config layer и преобразуется в typed settings.

## 18. Функции и методы

- Один method — одна понятная ответственность.
- Большой boolean parameter list является запахом; заменить options object/enum.
- Не передавать nullable callback как universal behavior switch.
- Named arguments использовать, когда они улучшают читаемость и API считается стабильным.
- Public method contract минимален и устойчив.

## 19. Side effects

Pure computation отделяется от I/O, clock, network, environment и process state. Это повышает тестируемость и делает long-running process предсказуемее.

## 20. Long-running PHP

В long-running process особенно важны:

- отсутствие unbounded caches/arrays;
- освобождение references/resources;
- отсутствие per-request global mutable state;
- re-entrant handler behavior;
- явное lifecycle management;
- periodic work без drift/overlap;
- signal/shutdown semantics;
- memory usage tests для потенциально долгих циклов.

## 21. Комментарии

Комментарий объясняет **почему**, invariant, workaround или external constraint; не пересказывает код.

```php
// Используем фиксированный timestamp, чтобы одинаковые исходники давали одинаковый PHAR.
```

Комментарии, TODO/FIXME и человекочитаемая часть PHPDoc MUST быть на русском.

## 22. Deprecations

PHP 8.4 deprecations/warnings MUST рассматриваться как реальные defects. Нельзя подавлять новый warning глобальным `error_reporting` или `@` вместо исправления причины.

Перед использованием новой возможности PHP проверять, что target runtime действительно 8.4+ и static tools настроены на 80400.

## 23. Возможности PHP 8.4

Новые возможности PHP 8.4 использовать только когда они действительно упрощают contract. В частности:

- property hooks допустимы для локального инварианта/нормализации свойства, но MUST не скрывать I/O, network calls или тяжёлую business logic;
- asymmetric visibility (`public private(set)` и аналоги) SHOULD использоваться вместо ручных getter-only конструкций, когда это делает ownership очевиднее;
- lazy objects/reflection-level механизмы не вводить в прикладной код без framework/infrastructure причины;
- новая синтаксическая возможность не является причиной усложнять простой код.

Статические инструменты MUST анализировать код как PHP 8.4 (`80400`), независимо от версии PHP на случайной машине разработчика.

## 24. Тестируемость

- Не вводить static/global state ради удобства.
- Clock/random/filesystem/network boundaries изолировать, если детерминизм важен.
- Unit test проверяет behavior, не внутренние private методы.
- Integration test проверяет реальные adapter contracts.
- Не мокать value objects.

## 25. Checklist

- [ ] `declare(strict_types=1)`.
- [ ] Native types максимально точны.
- [ ] Нет `mixed` без boundary-причины.
- [ ] Нет service locator/global mutable state.
- [ ] Exception/log/diagnostic human text на русском.
- [ ] External input валидируется.
- [ ] I/O errors проверяются.
- [ ] PHAR filesystem не считается writable.
- [ ] Tests обновлены.
- [ ] PHPCS и PHPStan проходят без новых suppressions.

## Источники

- PSR-12: https://www.php-fig.org/psr/psr-12/
- PER Coding Style: https://www.php-fig.org/per/coding-style/
- PHP 8.4 migration guide: https://www.php.net/manual/en/migration84.php
- PHP language reference: https://www.php.net/manual/en/langref.php
