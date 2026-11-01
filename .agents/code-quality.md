# Code quality: обязательный quality gate

## 1. Принцип

Quality tools являются исполняемым продолжением style guides. Новый код не считается готовым, если он проходит review «на глаз», но не проходит автоматические проверки.

Основная полная локальная команда:

```bash
composer quality
```

Она детерминированно запускает полный PHP gate, проверку Box/PHP-Scoper configuration и полный frontend gate независимо от Git context. GrumPHP используется как pre-commit coordinator и может быть запущен вручную через `composer grumphp`.

## 2. Инструменты PHP

### PHP_CodeSniffer 4 + PSR-12 + Slevomat

Назначение: формат, naming/conventions, типизационные и structural rules.

Конфигурация: `phpcs.xml.dist`.

Команды:

```bash
composer phpcs
composer phpcbf
```

PHPCBF MAY автоматически исправлять только безопасно исправляемые нарушения. После PHPCBF всё равно выполнить полный quality gate.

External standard discovery выполняет `dealerdirect/phpcodesniffer-composer-installer`.

### PHPStan 2.2 + strict-rules

Назначение: type safety, unreachable/invalid behavior, contract consistency.

Конфигурация: `phpstan.neon.dist`, `level: max`, `phpVersion: 80400`.

```bash
composer phpstan
```

Правила suppressions:

- сначала исправить тип/архитектуру;
- затем улучшить stub/annotation, если проблема во внешнем contract;
- локальный `@phpstan-ignore` допустим только при доказанном false positive и MUST содержать понятную причину;
- global `ignoreErrors` только для systematic external incompatibility с узким pattern/path;
- запрещено выключать whole rule/category ради одного места.

### PHPUnit 13

Tests — часть quality gate, не отдельная «опция перед релизом».

```bash
composer test
```

Policy:

- changed behavior → changed test;
- regression → test, который падает до fix;
- command contracts → integration tests через Console Tester;
- pure services → unit tests;
- network/filesystem integration → отдельные integration tests;
- flaky test нельзя «перезапускать до зелёного» — исправить nondeterminism.

### Parallel Lint

Все PHP files проходят syntax lint отдельно от PHPCS/PHPStan.

## 3. Frontend tools

### ESLint 10

Только flat config `eslint.config.mjs`. `.eslintrc*` и `.eslintignore` не вводить.

Проверяет JS/TS/Svelte script semantics и запрещает legacy/debug patterns.

```bash
npm run lint:js
```

### TypeScript / Svelte Check

Vite не выполняет type check. Обязательны:

```bash
npm run typecheck
npm run typecheck:config
```

`svelte-check` проверяет Svelte/TS/a11y-aware diagnostics; `tsc` отдельно проверяет build/config TypeScript.

### Stylelint 17

Проверяет CSS и `<style>` blocks `.svelte` через `postcss-html`.

```bash
npm run lint:css
```

### Полный frontend gate

```bash
npm run quality
```

## 4. GrumPHP

GrumPHP устанавливает Git hooks и для затронутых файлов координирует быстрый pre-commit gate:

- PHP 8.4 runtime check;
- strict Composer validation;
- PHP syntax lint;
- PHPCS;
- full PHPStan;
- PHPUnit;
- frontend `npm run quality` для frontend-related changes;
- blacklist debug constructions.

GrumPHP не заменяет полный CI gate: task selection зависит от Git context/затронутых файлов. `composer quality` MUST оставаться основной полной командой перед завершением задачи и в CI.

### Box/PHP-Scoper config validation

Полный `composer quality` MUST запускать `box validate`. Это дешёвая проверка packaging/scoping configuration без полной сборки PHAR. Сам scoping проверяется уже end-to-end при PHAR build в CI.

`scoper.inc.php` является исполняемым PHP-конфигом и также проходит syntax lint/PHPCS/PHPStan там, где применимо.

## 5. Почему PHAR build не в pre-commit

Packaging и reproducibility — более тяжёлая integration/build verification. Они выполняются:

- явно через `composer build` + `.ci/scripts/verify-phar.sh`;
- в каждом CI pipeline.

Не надо заставлять каждый маленький commit дважды собирать PHAR локально. Но merge в защищённую ветку без CI packaging check запрещён.

## 6. Auto-fix

```bash
composer fix
```

Запускает PHPCBF + frontend autofix.

Auto-fix не должен:

- скрывать semantic issue;
- менять public behavior без review;
- массово переписывать generated output;
- использоваться вместо понимания lint rule.

## 7. Baseline/ignore policy

В новом проекте запрещено создавать огромный static-analysis baseline «чтобы стало зелёным». Baseline MAY появиться только при подключении legacy code и должен иметь план постепенного уменьшения.

Новый/изменённый код не должен добавлять baseline errors.

## 8. Generated directories

Не анализируются как source:

- `vendor/`;
- `vendor-bin/*/vendor/`;
- `node_modules/`;
- `frontend/dist/`;
- `build/`;
- `.box/`;
- `artifacts/`.

## 9. Lock-файлы

Boilerplate поставляется без lock-файлов, потому что после rename/package initialization identity/versions могут измениться. В реальном repository после первого успешного dependency resolution MUST закоммитить:

- `composer.lock`;
- `package-lock.json`.

CI после этого MUST использовать `composer install` и `npm ci`, не `update/install` с плавающим resolution.

## 10. Dependency update policy

- Runtime dependency upgrade — отдельный reviewed change.
- Major upgrade MUST сверяться с migration notes.
- Quality tools можно обновлять чаще, но нельзя бездумно disable новые rules.
- Node/PHP target versions синхронизировать с CI Dockerfile.
- Box и PHP-Scoper изолированы, но их версии также должны быть lock/pinned через `vendor-bin/box/composer.lock` после init.

## 11. Human-readable diagnostics

Сообщения custom lint/build scripts, test descriptions/messages, комментарии и diagnostics проекта MUST быть на русском. Output стороннего инструмента, конечно, не переписывается.

## 12. Рекомендуемый локальный цикл

```bash
composer install
composer bin box install
npm ci
composer quality
composer build
sh .ci/scripts/verify-phar.sh
```

При frontend-only работе можно чаще запускать `npm run quality`/`npm run dev`, но перед завершением задачи всё равно нужен full gate.

## 13. Checklist изменения quality config

- [ ] Rule соответствует style guide.
- [ ] Target versions актуальны.
- [ ] Не добавлено широкое suppression.
- [ ] Generated/vendor dirs исключены.
- [ ] CI использует ту же команду, что локальная разработка.
- [ ] Documentation обновлена.

## Источники

- GrumPHP: https://github.com/phpro/grumphp
- PHP_CodeSniffer: https://github.com/PHPCSStandards/PHP_CodeSniffer
- Slevomat: https://github.com/slevomat/coding-standard
- PHPStan: https://phpstan.org/
- PHPUnit: https://phpunit.de/
- ESLint: https://eslint.org/
- Stylelint: https://stylelint.io/
- Svelte Check: https://www.npmjs.com/package/svelte-check
