# AGENTS.md — правила разработки Acme CLI

Этот файл — основная точка входа для coding agent и разработчика. Проект является PHP 8.4 CLI-приложением на Symfony Console, может запускать long-running HTTP-сервисы на ReactPHP, содержит опциональный web UI на Vite + TypeScript + Svelte и собирается в самостоятельный PHAR.

## 1. Приоритет правил

При конфликте требований применять их в следующем порядке:

1. явная задача пользователя/issue;
2. этот `AGENTS.md`;
3. предметные гайды `.agents/codestyle/cli.md`, `symfony-console.md`, `reactphp.md`, `phar.md`;
4. языковые гайды `.agents/codestyle/php.md`, `js.md`, `typescript.md`, `svelte.md`, `css.md`;
5. `.agents/code-quality.md` и `.agents/ci-cd.md`;
6. conventions существующего кода, если они не противоречат правилам выше.

Если API или поведение библиотеки зависит от версии, агент MUST сначала проверить реально зафиксированную версию в `composer.lock`/`package-lock.json` и актуальную официальную документацию. Нельзя выдумывать API по памяти.

## 2. Обязательные документы

Перед изменением соответствующей области прочитать:

- PHP: `.agents/codestyle/php.md`;
- CLI UX: `.agents/codestyle/cli.md`;
- Symfony Console: `.agents/codestyle/symfony-console.md`;
- ReactPHP/long-running server: `.agents/codestyle/reactphp.md`;
- PHAR/build/runtime filesystem: `.agents/codestyle/phar.md`;
- изоляция PHP-кода и зависимостей: `.agents/codestyle/php-scoper.md`;
- JavaScript: `.agents/codestyle/js.md`;
- TypeScript: `.agents/codestyle/typescript.md`;
- Svelte: `.agents/codestyle/svelte.md`;
- CSS: `.agents/codestyle/css.md`;
- Vite/frontend build: `.agents/codestyle/vite.md`;
- quality tools/tests: `.agents/code-quality.md`;
- CI/CD: `.agents/ci-cd.md`.

## 3. Язык человекочитаемого нелокализуемого текста

**Глобальный MUST:** весь человекочитаемый текст, который создаётся проектом и не проходит через отдельный механизм локализации, пишется только на русском языке.

Это относится как минимум к:

- комментариям и поясняющей части PHPDoc/JSDoc/TSDoc;
- сообщениям исключений;
- логам;
- диагностике;
- сообщениям CLI;
- текстам assertion/error в собственном коде;
- TODO/FIXME;
- сообщениям build/CI scripts;
- сообщениям HTTP API, если они предназначены человеку и не являются локализуемым UI;
- сообщениям тестов, fixtures и демонстрационных примеров, если это человекочитаемый текст.

Не переводятся идентификаторы, protocol fields, machine error codes, имена API/классов/методов, команды, параметры и общеупотребимые технические термины там, где перевод ухудшает точность.

Если UI в будущем станет мультиязычным, пользовательские UI-строки MUST быть вынесены в локализацию. Наличие русского языка по умолчанию не является основанием для хардкода локализуемого интерфейса.

## 4. Архитектурные инварианты

- Команды Symfony Console MUST быть тонкими orchestration/adaptor слоями.
- Business/application logic MUST жить в сервисах, не в `execute()`.
- Зависимости MUST передаваться явно через constructor DI.
- Глобальный service locator, mutable singleton и скрытые container lookups в прикладном коде запрещены.
- Composition root находится в `src/Bootstrap`.
- Symfony DI container допустим в composition root; прикладные классы MUST не зависеть от контейнера.
- Для long-running ReactPHP кода запрещён неограниченный blocking I/O в event loop.
- PHAR считается read-only deployment unit. Runtime MUST не писать рядом с `__FILE__`, `__DIR__` или внутрь `phar://`.
- Frontend source живёт в `frontend/src`; `frontend/dist` генерируется Vite и не редактируется вручную.
- В PHAR попадает только production runtime + собранный `frontend/dist`, но не source frontend/dev tooling/tests/CI.
- Runtime dependencies и first-party PHP-код внутри PHAR MUST быть изолированы PHP-Scoper. Expose разрешён только для узкого внешнего API/contract namespace, если такой API реально существует.
- Scoper prefix MUST быть фиксированным для данного приложения, иначе воспроизводимая сборка невозможна.

## 5. Целевой стек

- PHP: 8.4+;
- Symfony Console / DependencyInjection: 8.1 line;
- ReactPHP HTTP: 1.11+;
- PHPUnit: 13;
- PHPStan: 2.2, `level: max` + strict rules;
- PHPCS: 4 + PSR-12 + Slevomat;
- GrumPHP: 2.23+;
- Node.js: 22.13+ (LTS) или 24+;
- Vite: 8.1+;
- TypeScript: 6.0+;
- Svelte: 5;
- ESLint: 10 flat config;
- Stylelint: 17;
- Box: 4.7+, установлен изолированно через `composer-bin-plugin`;
- PHP-Scoper: 0.18.19+, используется Box через `PhpScoper` compactor.

Версии в lock-файлах имеют приоритет над этим справочным списком.

## 6. Definition of Done для изменения

Перед завершением задачи агент MUST:

1. проверить архитектурное размещение изменения;
2. добавить/изменить tests для изменяемого поведения;
3. выполнить или обеспечить прохождение полного `composer quality`;
4. если затронут frontend — обеспечить `npm run quality` и `npm run build`;
5. если затронута упаковка/runtime — выполнить сборку PHAR и smoke verification;
6. не оставлять debug output (`var_dump`, `print_r`, `console.log`, `debugger`);
7. проверить, что новые человекочитаемые нелокализуемые сообщения только на русском;
8. обновить документацию при изменении public CLI/API/build contract.

Если инструмент нельзя выполнить в текущей среде, это MUST быть явно сказано; нельзя утверждать, что проверка пройдена.

## 7. Не делать без отдельной причины

- не добавлять framework ради одной функции;
- не использовать Symfony FrameworkBundle/ConsoleBundle автоматически: этот boilerplate сознательно остаётся standalone Console application;
- не вводить writable Symfony kernel cache внутрь PHAR;
- не прятать ошибки через широкие `catch (Throwable) {}`;
- не подавлять PHPStan/ESLint/Stylelint без локального объяснения причины;
- не отключать проверку целого каталога ради одной несовместимости;
- не добавлять runtime-зависимость на Node/npm;
- не выполнять `composer update` в production runtime;
- не пересобирать PHAR в deployment job из другого набора исходников — CI artifact должен быть неизменяемым входом последующих стадий;
- не коммитить `vendor`, `node_modules`, `frontend/dist`, `build`, `artifacts`.

## 8. Базовые команды

```bash
composer install
composer bin box install
npm ci
composer quality
composer build
php build/acme-cli.phar --version
php build/acme-cli.phar hello Мир
php build/acme-cli.phar serve
```

Для чистого boilerplate без lock-файлов первый bootstrap использует `composer update` и `npm install`; после инициализации конкретного проекта оба lock-файла MUST быть сгенерированы и закоммичены.

## 9. Источники

- Symfony Console: https://symfony.com/doc/current/components/console.html
- Symfony Console commands: https://symfony.com/doc/current/console.html
- Symfony DependencyInjection: https://symfony.com/doc/current/components/dependency_injection.html
- ReactPHP HTTP: https://reactphp.org/http/
- Box: https://box-project.github.io/box/
- PHP-Scoper: https://github.com/humbug/php-scoper
- Vite: https://vite.dev/
- TypeScript: https://www.typescriptlang.org/docs/
- Svelte: https://svelte.dev/docs/svelte/overview
