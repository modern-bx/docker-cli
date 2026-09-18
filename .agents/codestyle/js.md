# JavaScript: общий код-стайл

## 1. Область

JavaScript остаётся частью проекта для build/config/runtime browser code. Для нового frontend application code при наличии выбора предпочтителен TypeScript; JavaScript допустим для config/tooling и небольших modules, где TS не даёт практической выгоды.

Цель: современный ECMAScript, modules-first, `const`-first, без legacy constructs.

## 2. Базовые правила

MUST:

- `const` по умолчанию, `let` только при reassignment;
- `var` запрещён;
- ES modules (`import`/`export`);
- strict equality `===`/`!==`;
- фигурные скобки для control structures;
- semicolon policy соответствует formatter/linter проекта;
- глобальные переменные не создавать;
- `eval`, `new Function` запрещены;
- `console.log` в committed code запрещён.

## 3. Современный ECMAScript

Использовать platform features целевой среды, если они поддерживаются Vite/target browsers/Node 22.13+. Не добавлять polyfill случайно ради одной функции.

Предпочитать:

- optional chaining `?.`;
- nullish coalescing `??`;
- destructuring, когда улучшает читаемость;
- object spread для shallow immutable update;
- `for...of` для imperative iteration;
- `Array.from`, `Map`, `Set` по semantics;
- private class fields, если encapsulation действительно нужна;
- async/await для readable async flow.

## 4. Classes

Class использовать для объекта с identity/state/lifecycle или polymorphic behavior. Не оборачивать каждую pure function в static utility class.

- class `PascalCase`;
- methods/properties `camelCase`;
- private implementation `#private`/module scope;
- constructor не должен выполнять тяжёлый I/O;
- inheritance редкое, composition default.

## 5. Functions

Pure functions предпочтительны для transformations. Function должна иметь понятный input/output и не зависеть от hidden globals.

Не использовать boolean positional soup:

```js
// Плохо
render(data, true, false, true);
```

Лучше options object.

## 6. Equality и coercion

Implicit coercion использовать только там, где semantics очевидна и стандартна. Валидация внешних данных — explicit. `Boolean(value)`/`Number(value)` лучше магических `!!`/unary tricks в сложном code.

## 7. Null/undefined

Внутри проекта выбирать одну semantics отсутствия значения. Обычно `undefined` — omitted/not provided, `null` — explicit empty только если это часть wire/domain contract.

Не использовать `value || default`, если `0`, `''`, `false` допустимы; применять `??`.

## 8. Arrays/objects

Не мутировать argument без явного contract. Avoid hidden shared mutable objects.

- `.map()` — mapping, не side effects;
- `.filter()` — filtering;
- `.some()`/`.every()` — predicates;
- `.find()` — один элемент;
- `forEach()` не нужен для async work.

## 9. Async

- Каждый Promise должен быть awaited/returned/handled.
- `async` function всегда возвращает Promise — учитывать в API.
- Не делать `array.forEach(async () => ...)`.
- `Promise.all` только когда concurrency безопасна и bounded.
- Для потенциально большого списка concurrency ограничивать.
- `AbortSignal` использовать там, где API поддерживает cancellation.
- Не swallow rejection.

## 10. Errors

Throw `Error`/specialized error, не строки.

```js
throw new Error('Не найден корневой элемент приложения.');
```

Human-readable exception/diagnostic text MUST быть на русском.

При `catch` значение считать `unknown` концептуально; не предполагать shape случайного thrown value.

## 11. DOM

- Query selector result проверять на `null`.
- `textContent` предпочтительнее HTML insertion.
- `innerHTML`/`insertAdjacentHTML` для недоверенного content запрещены без доказанной sanitization boundary.
- Event handlers cleanup требуется у manually managed lifecycle.
- UI state не хранить в DOM как единственном source of truth, если framework state уже существует.

## 12. Browser storage

LocalStorage/sessionStorage — недоверенное persisted input. Парсинг + validation обязательны. Secrets туда не помещать.

## 13. Networking

- Check HTTP status explicitly.
- Parse response according to declared content type/contract.
- timeout/cancellation policy должна существовать для long requests.
- credentials/token не логировать.
- Network layer отделить от view component при существенной логике.

## 14. Modules

- Один module — cohesive responsibility.
- Circular dependencies избегать.
- Barrel exports не вводить механически: они могут скрывать cycles/tree-shaking boundaries.
- Side-effect imports только там, где side effect является осознанной частью module contract (например global CSS entry).

## 15. Imports

- Dependencies import from public package entry points.
- Не импортировать undocumented internal package file.
- Relative imports не должны превращаться в `../../../../`; при росте проекта настроить alias осознанно.

## 16. Security

Запрещено:

- `eval`;
- dynamic code execution из external input;
- unsanitized HTML;
- секреты в frontend bundle;
- доверие к client-side authorization;
- insecure random для security tokens;
- prototype pollution через blind object merge external data.

## 17. Performance

Не оптимизировать микрооперации без profile. Но избегать очевидного:

- repeated full DOM query в hot loop;
- unbounded event listener creation;
- огромные synchronous JSON parse/render без причины;
- duplicate large dependency;
- storing megabytes reactive state без нужды.

## 18. Comments

Comment объясняет constraint/reason/workaround. Не писать комментарий, который просто пересказывает следующую строку. Все human comments/TODO/FIXME — на русском.

## 19. Generated code

`frontend/dist` — generated output. Не lint/edit вручную. Править source/config и пересобирать.

## 20. Checklist

- [ ] Нет `var`.
- [ ] Нет globals/debug console.
- [ ] ES modules.
- [ ] Async errors handled.
- [ ] DOM null/HTML safety учтены.
- [ ] Human diagnostics на русском.
- [ ] Нет secret в bundle/storage.
- [ ] ESLint проходит без новых disable blocks.

## Источники

- ECMAScript: https://tc39.es/ecma262/
- MDN JavaScript Guide: https://developer.mozilla.org/docs/Web/JavaScript/Guide
- MDN Modules: https://developer.mozilla.org/docs/Web/JavaScript/Guide/Modules
- ESLint: https://eslint.org/docs/latest/
