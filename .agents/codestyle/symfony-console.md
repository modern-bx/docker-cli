# Symfony Console 8.1: правила проекта

## 1. Standalone Console application

Проект использует компоненты `symfony/console` и `symfony/dependency-injection` без полного Symfony FrameworkBundle. Это сознательное решение для компактного CLI/PHAR.

Symfony 8.1 `Application` может получать PSR-11 container и загружать command services через `console.command.ids`. В boilerplate это используется в `ApplicationFactory` + `ContainerFactory`.

## 2. Composition root

Container создаётся только в bootstrap layer. Registration explicit и проверяемая.

MUST:

- command/service dependencies через constructor;
- compile container до запуска приложения;
- application/service code не получает container целиком;
- bootstrap не содержит business rules.

## 3. Commands

Новая команда:

- `final`;
- имеет `#[AsCommand]` с name/description;
- вызывает `parent::__construct()`;
- configuration объявляет arguments/options;
- `execute()` только валидирует boundary input, вызывает service и отображает outcome;
- возвращает explicit exit code.

Не помещать в command:

- SQL/network integration implementation;
- complex filesystem algorithm;
- domain calculation;
- reusable HTTP logic;
- hidden global configuration reads.

## 4. SymfonyStyle

Для human-oriented command UI использовать `SymfonyStyle`: `title`, `section`, `note`, `warning`, `error`, `success`, tables/questions. Это даёт единый semantic output.

Не использовать `SymfonyStyle` внутри application service — service не должен знать о terminal UI.

## 5. Input

- `getArgument()/getOption()` возвращают broad types: boundary MUST сузить/валидировать их до передачи в typed service.
- Enum-like values преобразовать в enum/value object.
- File path нормализовать только в рамках нужной semantics.
- Не доверять option только потому, что он пришёл из CLI.

## 6. Output

Service возвращает typed result/data; command решает, как представить его человеку. Для machine output лучше отдельный formatter/renderer, а не `if ($json)` по всему service layer.

## 7. Error mapping

Expected input/config errors преобразуются в понятное сообщение + `INVALID`/`FAILURE`. Unexpected exception не должна теряться. Если вводится application exception hierarchy, mapping выполняется централизованно или в boundary command, но не через blanket catch с suppression.

## 8. Lazy loading

Если количество команд/зависимостей станет большим, MAY перейти на command loader/lazy services. Оптимизацию вводить после измерения startup time/memory, не заранее.

## 9. Console events

Console events использовать для cross-cutting concerns, если это действительно global behavior: telemetry, standardized exception rendering, lifecycle hook. Не скрывать обычную business orchestration в listener.

## 10. Testing

- Unit tests: service без Console.
- Command integration: `ApplicationTester`/`CommandTester`.
- Проверять exit code и meaningful output.
- Interactive command: test input stream.
- Не assert exact ANSI formatting без необходимости.

## 11. Version

Application name/version централизованы в `AppMetadata`. Build pipeline/rename/version bump MUST не оставлять несовпадающие версии в разных местах.

## 12. PHAR compatibility

- Не требовать writable Framework cache dir.
- Не строить runtime container dump внутрь PHAR.
- Resource lookup должен работать из `phar://`.
- Autoload должен быть уже включён Box в PHAR.

## 13. Checklist команды

- [ ] `#[AsCommand]`.
- [ ] `final`.
- [ ] constructor DI.
- [ ] logic вне `execute()`.
- [ ] input валидируется/типизируется.
- [ ] `SymfonyStyle` только в UI layer.
- [ ] русские human messages.
- [ ] explicit exit code.
- [ ] command test.

## Источники

- Symfony Console component: https://symfony.com/doc/current/components/console.html
- Console commands: https://symfony.com/doc/current/console.html
- SymfonyStyle: https://symfony.com/doc/current/console/style.html
- DependencyInjection component: https://symfony.com/doc/current/components/dependency_injection.html
