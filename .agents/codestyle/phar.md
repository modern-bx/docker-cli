# PHAR: упаковка, воспроизводимость и runtime

## 1. Deployment model

PHAR — immutable application artifact. Исходники, vendor runtime dependencies и собранный frontend упаковываются в один executable `.phar`.

Проект использует Box 4.7+ как build tool и PHP-Scoper как его `PhpScoper` compactor. Оба находятся в isolated `vendor-bin/box`, чтобы build-tool dependencies не конфликтовали с runtime Symfony dependencies приложения.

## 2. Box/PHP-Scoper не являются runtime dependencies

Box и PHP-Scoper MUST отсутствовать в production dependency graph/root `require`. Их isolated Composer project лежит в `vendor-bin/box`.

CI:

```bash
composer bin box install
composer build
```

`phar.readonly` отключается только для процесса Box через Composer script (`php -d phar.readonly=0 ...`). Runtime остаётся совместимым с безопасным обычным `phar.readonly=1`. Scoping выполняется внутри Box до финального autoload dump.

## 3. Что входит в PHAR

MUST:

- `bin/acme-cli` как main;
- `src`;
- production Composer dependencies, найденные Box и изолированные PHP-Scoper;
- `frontend/dist` как binary/static directory.

MUST NOT:

- `.agents`, `.ci`, repository CI configs;
- tests;
- tools;
- frontend source;
- `node_modules`;
- vendor-bin Box dependencies;
- build cache;
- secrets/config конкретной среды.

## 4. Frontend до PHAR

PHAR build MUST зависеть от успешного `vite build`. Нельзя упаковать stale/отсутствующий `frontend/dist`.

CI проверяет наличие `frontend/dist/index.html` и Vite manifest.

## 5. PHP-Scoper и namespace isolation

Box MUST регистрировать `KevinGH\Box\Compactor\PhpScoper` и ссылаться на `scoper.inc.php`. Ручной отдельный `php-scoper add-prefix` перед Box не нужен и создаёт лишний шанс разойтись с тем набором файлов, который реально кладётся в PHAR. Box при интеграции сам использует включённые non-binary files, корректирует autoload и собирает конечный archive.

`prefix` MUST быть явным и стабильным. `null`/случайный prefix несовместим с byte-for-byte reproducibility. Rename tooling MUST выводить prefix из PHP namespace и менять его вместе с application identity.

По умолчанию first-party и vendor namespaces не expose/exclude. Если появляется внешний plugin/API contract, MAY быть exposed только узкий contract namespace. Любое новое исключение должно иметь конкретную integration-причину и end-to-end test.

Если приложение динамически загружает внешний PHP-код/plugins в тот же процесс, scoping bundled dependencies является обязательным: внешний код не должен случайно получить встроенную версию Symfony/ReactPHP только из-за совпадения namespace.

PHP-Scoper имеет ограничения для динамически построенных class names, callables, reflection, строковых service IDs и некоторых framework configs. После добавления зависимости, активно использующей такие механизмы, MUST проверить собранный PHAR реальным сценарием; при необходимости использовать узкий patcher/expose rule, а не отключать scoping целиком.

## 6. Read-only filesystem

Нельзя писать:

```php
file_put_contents(__DIR__ . '/cache.json', $data);
```

В PHAR `__DIR__` может указывать внутрь `phar://`. Writable state должен жить во внешнем каталоге:

- user config dir;
- user cache dir;
- explicit `--data-dir`;
- OS temp dir;
- другое документированное external storage.

## 7. Resource path

Для bundled resources использовать abstraction, которая различает source-tree и PHAR mode через `Phar::running(false)`.

Не размазывать `phar://` string construction по всему приложению.

## 8. Reproducible build

Box config задаёт fixed timestamp. Build MUST быть детерминирован при одинаковых:

- source tree;
- composer.lock;
- package-lock.json;
- PHP/Node/tool versions;
- build environment.

CI собирает PHAR повторно и сравнивает SHA-256.

Если reproducibility нарушилась, нельзя просто удалить проверку: найти nondeterministic input (timestamps, generated random IDs, absolute paths, unstable order).

## 9. Compression

Default boilerplate использует `compression: NONE` для максимальной runtime portability. GZ/BZ2 MAY быть включены только после осознанного решения, поскольку добавляют runtime extension requirement и меняют performance trade-off.

## 10. Signature hash

Box настроен на `algorithm: SHA256`. Это integrity hash PHAR, а не полноценная publisher authenticity/signing chain.

Пока release/signing не реализованы, CI публикует рядом `.sha256`. В будущем release signing MUST быть отдельным security design, а не предположением, что встроенного hash достаточно.

## 11. phar.readonly

`phar.readonly=1` допустим и желателен в runtime. Build tool должен сам корректно организовать compile environment; приложение никогда не должно модифицировать собственный PHAR.

## 12. Runtime requirements

Box `check-requirements` MUST оставаться включённым. Если добавляется обязательное PHP extension, оно должно появиться в Composer/platform requirements и CI runtime smoke test.

## 13. Bootstrap

Main script:

- имеет shebang;
- strict types;
- подключает Composer autoload;
- создаёт Application через composition root;
- возвращает exit code.

Никакой business logic в stub/main.

## 14. Self-update

Не добавлять self-update автоматически. Это отдельная supply-chain задача: channel, signatures, rollback, atomic replacement, Windows semantics, proxy/TLS. Пока релизов нет, PHAR только CI artifact.

## 15. CI verification

После build MUST:

- запустить `php app.phar --version`;
- выполнить хотя бы одну реальную command smoke test;
- выполнить dependency-isolation smoke test с конфликтующим внешним vendor class;
- проверить, что PHP-Scoper реально префиксовал и runtime dependency namespace, и first-party application namespace;
- открыть PHAR и проверить обязательные entries;
- проверить отсутствие dev entries;
- проверить SHA-256 signature type;
- повторить build и сравнить hash;
- положить PHAR + `.sha256` в CI artifacts.

## Источники

- PHP Phar: https://www.php.net/manual/en/book.phar.php
- Box: https://box-project.github.io/box/
- Box configuration: https://box-project.github.io/box/configuration/
- PHP-Scoper: https://github.com/humbug/php-scoper
- PHP-Scoper configuration: https://github.com/humbug/php-scoper/blob/main/docs/configuration.md
- Composer bin plugin: https://github.com/bamarni/composer-bin-plugin
