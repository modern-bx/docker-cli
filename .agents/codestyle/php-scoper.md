# PHP-Scoper: изоляция кода внутри PHAR

## 1. Зачем он нужен

PHAR исполняется в обычном PHP-процессе. Если приложение динамически подключает внешние PHP-классы, плагины, Composer-проекты или иной код, одинаковые namespaces/classes/functions могут конфликтовать с версиями, уже упакованными внутрь PHAR.

PHP-Scoper префиксует bundled PHP symbols. В этом проекте он используется только через Box `KevinGH\Box\Compactor\PhpScoper`.

## 2. Интеграция только через Box

MUST:

```json
{
  "compactors": [
    "KevinGH\\Box\\Compactor\\PhpScoper"
  ],
  "php-scoper": "scoper.inc.php"
}
```

Не строить отдельное дерево `build/scoped` перед Box без специальной причины. При штатной интеграции Box передаёт PHP-Scoper именно те non-binary files, которые реально входят в PHAR, и сам корректирует Composer autoload.

## 3. Версия и изоляция build tool

Box и PHP-Scoper живут в одном isolated Composer project:

```text
vendor-bin/box/composer.json
```

Root runtime `composer.json` MUST не содержать `humbug/box`/`humbug/php-scoper` в `require`.

Версии фиксируются `vendor-bin/box/composer.lock` после инициализации конкретного проекта.

## 4. Детерминированный prefix

`scoper.inc.php` MUST задавать явный prefix:

```php
return [
    'prefix' => 'AcmeCliPhar',
    'php-version' => '8.4',
];
```

Случайный prefix запрещён, потому что одинаковые исходники должны давать одинаковый PHAR SHA-256.

Rename script MUST формировать prefix из PHP namespace приложения и менять его вместе с binary/package/namespace identity. Это уменьшает вероятность коллизии одинаково названных CLI разных vendors.

## 5. First-party namespace

Default boilerplate ничего не expose:

```php
'expose-namespaces' => [],
```

Это даёт максимальную namespace isolation как first-party коду, так и bundled dependencies. Если позже появится внешний plugin API, SHOULD выделить отдельный namespace вроде `Vendor\Tool\PluginApi`/`Contracts` и expose только его, а не весь namespace приложения.

## 6. Vendor dependencies

MUST NOT массово expose/exclude `Symfony`, `React`, `Psr` или другие vendor namespaces только ради того, чтобы «сборка заработала».

Правильный порядок при проблеме:

1. воспроизвести проблему на собранном PHAR;
2. определить dynamic symbol/string/reflection место;
3. проверить документацию зависимости и PHP-Scoper;
4. добавить минимальный patcher/expose/exclude rule;
5. добавить regression smoke/integration test;
6. повторно проверить isolation conflict scenario.

## 7. PSR interfaces

Особенно осторожно относиться к PSR interfaces (`Psr\*`). Если внешний plugin API должен принимать/возвращать конкретный PSR interface, необходимо принять явное архитектурное решение: expose этого interface/package либо собственный boundary DTO/contract.

Нельзя случайно expose весь `Psr` namespace без понимания ABI/type identity последствий.

## 8. Dynamic loading

PHP-Scoper не может надёжно вывести все symbols из runtime strings. Рискованные конструкции:

- class name, собранный конкатенацией;
- service/container ID как строковый FQCN;
- `class_exists()`/`interface_exists()` с динамической строкой;
- reflection по строковому имени;
- callback `"Namespace\\Class::method"`;
- custom plugin discovery;
- serialized class names;
- PHP config, где FQCN записан обычной строкой.

Для first-party кода SHOULD использовать `Foo::class`, typed references и явные factories вместо строк, когда это возможно.

## 9. Patcher

Patcher допустим только для известного несовместимого места. Он MUST:

- быть узким по file path/content pattern;
- иметь русскоязычный комментарий с причиной;
- не выполнять широкую замену по всему vendor tree;
- иметь PHAR-level regression test;
- падать/становиться заметным после изменения upstream-кода, а не молча применять неверную замену.

## 10. Проверка изоляции

Обычный `php app.phar --version` недостаточен: unscoped PHAR тоже его пройдёт.

CI MUST дополнительно:

1. заранее объявить класс с именем одного bundled vendor class, например `Symfony\Component\Console\Application`;
2. затем запустить PHAR в этом же процессе;
3. убедиться, что реальная команда PHAR отрабатывает успешно.

Такой тест подтверждает, что приложение действительно использует prefixed dependency, а не оригинальный namespace. Дополнительно CI MUST открыть PHAR и убедиться, что namespace собственного приложения также получил ожидаемый prefix: scoping должен охватывать не только vendor, но и first-party PHP-код.

## 11. Отладка

При проблемах полезны:

```bash
vendor-bin/box/vendor/bin/box compile --debug
vendor-bin/box/vendor/bin/php-scoper inspect path/to/file.php --config=scoper.inc.php
```

Debug output `.box/` является временным build artifact и не коммитится.

## 12. Checklist

- [ ] Box `PhpScoper` compactor включён.
- [ ] `scoper.inc.php` имеет фиксированный prefix.
- [ ] Target `php-version` совпадает с PHP 8.4 baseline.
- [ ] First-party/vendor namespaces не exposed без причины.
- [ ] Dynamic class-name logic проверена.
- [ ] PHAR smoke test проходит.
- [ ] Conflict/isolation smoke test проходит.
- [ ] Внутри PHAR найдены prefixed namespace как минимум одной runtime dependency и собственного приложения.
- [ ] Повторная сборка даёт тот же SHA-256.

## Источники

- PHP-Scoper: https://github.com/humbug/php-scoper
- Configuration: https://github.com/humbug/php-scoper/blob/main/docs/configuration.md
- Further reading / Symfony support: https://github.com/humbug/php-scoper/blob/main/docs/further-reading.md
- Box PHP-Scoper integration: https://box-project.github.io/box/configuration/#php-scoper-php-scoper
