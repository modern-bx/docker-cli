# TypeScript: строгий frontend/application code

## 1. Зачем TypeScript

TypeScript используется для нового frontend code по умолчанию. Его задача — сделать contracts явными и ловить ошибки на compile/static-analysis этапе, а не заменить runtime validation.

Vite **не выполняет type checking** при transpilation. Поэтому `svelte-check` и отдельный `tsc --noEmit` являются обязательными quality steps.

## 2. Compiler contract

`tsconfig.json` MUST сохранять строгие настройки:

- `strict: true`;
- `isolatedModules: true`;
- `verbatimModuleSyntax: true`;
- `noUncheckedIndexedAccess: true`;
- `exactOptionalPropertyTypes: true`;
- `noImplicitOverride: true`;
- `useUnknownInCatchVariables: true`;
- `noEmit: true`.

Ослаблять один из этих flags можно только при документированной несовместимости, локально и осознанно.

## 3. `any`

Explicit `any` запрещён lint rule. На external boundary использовать `unknown` и narrowing/validation.

Плохо:

```ts
function parse(input: any) {
    return input.id;
}
```

Лучше:

```ts
function parse(input: unknown): string {
    if (!isRecord(input) || typeof input.id !== 'string') {
        throw new Error('Получены некорректные данные.');
    }

    return input.id;
}
```

## 4. Type inference

Не аннотировать очевидное:

```ts
const retries = 3;
```

Аннотация нужна на public boundaries, complex object contracts, empty collections, exported APIs или когда inference даёт слишком широкий type.

## 5. `type` vs `interface`

- `type` default для unions, mapped/utility types, local data contracts;
- `interface` уместен для extendable object shape/public polymorphic contract;
- не устраивать религиозный выбор: consistency и semantics важнее.

## 6. Type-only imports

С `verbatimModuleSyntax` MUST использовать `import type` для type-only dependencies.

```ts
import type { User } from './user';
```

Это делает runtime dependency graph прозрачным.

## 7. Unions

Discriminated union предпочтительнее комбинации nullable optional fields:

```ts
type LoadState<T> =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'success'; data: T }
    | { status: 'error'; message: string };
```

Switch по discriminant SHOULD быть exhaustive. При расширении union compiler должен заставить обработать новое состояние.

## 8. Optional properties

`foo?: string` с `exactOptionalPropertyTypes` означает property может отсутствовать, а не автоматически `string | undefined` для присваивания. Выбирать contract сознательно.

## 9. Nullability

Не применять non-null assertion `!` для «успокоения компилятора». Она допустима только если invariant действительно доказан вне type system и рядом понятна причина. В DOM query предпочтительнее explicit check.

## 10. Type assertions

`as SomeType` не является validation. External JSON/HTTP/storage должен быть проверен runtime schema/guard, прежде чем считаться typed.

Double assertion `as unknown as X` — красный флаг и почти всегда запрещён.

## 11. Enums

Для frontend wire/state значений обычно предпочтительнее literal union + `as const`, если не нужна runtime enum object semantics. Не использовать `enum` автоматически.

## 12. Generic types

Generic должен выражать связь типов. Не вводить `<T>` только для «универсальности». Ограничивать `T extends ...`, если implementation зависит от members.

## 13. Utility types

`Pick/Omit/Partial/Required` использовать умеренно. Public DTO, состоящий из пяти nested utility types, труднее читать и эволюционировать, чем named type.

`Partial<T>` не заменяет patch contract автоматически: важно различать omitted, undefined, null и reset semantics.

## 14. `Record`

`Record<string, T>` утверждает, что любое string key существует, что часто неправда. При sparse dictionary использовать `Partial<Record<Key, T>>`, `Map`, index signature с `T | undefined` или runtime check.

## 15. Functions

- return type exported/public function SHOULD быть явным;
- callback parameter types выводить, если inference точный;
- overloads использовать только если разные call signatures действительно дают разный type relationship;
- options object предпочтительнее большого positional signature.

## 16. Classes

- `private`/`protected`/`readonly` использовать по semantics;
- `override` обязателен при overriding благодаря `noImplicitOverride`;
- не использовать class как namespace static functions;
- class state должен иметь lifecycle/identity reason.

## 17. Error handling

`catch (error)` имеет `unknown`. Narrow:

```ts
catch (error: unknown) {
    const message = error instanceof Error
        ? error.message
        : 'Произошла неизвестная ошибка.';
}
```

Не отправлять raw unknown object человеку.

## 18. Async types

`Promise<void>` означает async completion без result, не «fire and forget». Detached promise должен иметь explicit `.catch(...)` и lifecycle reason; лучше его избежать.

## 19. DOM types

Использовать generic query:

```ts
const root = document.querySelector<HTMLElement>('#app');
```

но всё равно проверять `null`. Generic selector не доказывает существование элемента.

## 20. Svelte integration

- Svelte components: `<script lang="ts">`.
- Props типизированы.
- Events/callbacks имеют precise signatures.
- Universal reactive modules в `.svelte.ts`, если используются runes вне component.
- `svelte-check` — основной Svelte-aware checker.

## 21. Runtime schemas

При существенном external JSON MAY добавить runtime validation library (например Zod/Valibot) после оценки bundle/runtime cost. TypeScript type alone не делает input безопасным.

## 22. Declarations

Не писать ambient global `.d.ts` для обхода типов сторонней библиотеки без проверки, существует ли официальный type package. Local declaration MUST описывать реальный runtime contract и быть минимальной.

## 23. Comments и names

Identifiers обычно английские технические/domain names. Человекочитаемые комментарии, TODO/FIXME, exception messages — на русском.

## 24. Checklist

- [ ] `strict` flags сохранены.
- [ ] Нет `any`.
- [ ] External input проходит runtime narrowing.
- [ ] Type-only imports корректны.
- [ ] Нет необоснованных `!`/`as`.
- [ ] Union состояния discriminated/exhaustive.
- [ ] `svelte-check`/`tsc` проходят.
- [ ] Human diagnostics на русском.

## Источники

- TypeScript handbook: https://www.typescriptlang.org/docs/handbook/intro.html
- TS config reference: https://www.typescriptlang.org/tsconfig/
- Vite TypeScript notes: https://vite.dev/guide/features.html#typescript
- TypeScript 6.0 docs/releases: https://devblogs.microsoft.com/typescript/
