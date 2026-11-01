# Svelte 5: components, runes и accessibility

## 1. Modern Svelte 5

Новый код MUST использовать современную rune-based модель Svelte 5. Legacy syntax допускается только при работе с существующим legacy component/совместимостью.

Основные runes:

- `$state` — local reactive state;
- `$derived` — вычисляемое состояние;
- `$effect` — side effect на внешнюю систему;
- `$props` — props;
- `$bindable` — только когда two-way binding является осознанным API.

## 2. Derived state

Если значение может быть вычислено из state, использовать `$derived`, а не `$effect`, который вручную синхронизирует второе состояние.

Плохо: effect копирует `firstName + lastName` в mutable `fullName`.

Хорошо: `const fullName = $derived(...)`.

## 3. Effects

`$effect` — escape hatch для external side effects: DOM API, analytics, non-Svelte library, subscriptions, persistence. Не использовать effect как основной механизм business flow.

Effect MUST иметь cleanup, если создаёт listener/subscription/timer/resource.

## 4. Props

Props типизируются:

```svelte
<script lang="ts">
    interface Props {
        title: string;
        disabled?: boolean;
    }

    let { title, disabled = false }: Props = $props();
</script>
```

Не мутировать prop как hidden channel. Child-owned state отделять от parent input.

## 5. Events

В современном Svelte предпочтительны callback props/DOM event properties согласно текущему API. Не вводить custom event bus между компонентами без необходимости.

## 6. Component responsibility

Component отвечает за один cohesive UI concern. Большой component разделять по behavior/layout boundaries, а не по arbitrary line count.

Business/network logic при росте выносить в TypeScript service/module. Component остаётся presentation/orchestration layer.

## 7. State ownership

State должен жить в самом узком месте, где он нужен. Не поднимать всё в global store.

Cross-component universal reactive state MAY жить в `.svelte.ts` module. Store использовать, когда нужен store contract/interoperability или он лучше выражает поток данных.

## 8. Accessibility

Svelte compiler и `svelte-check` accessibility warnings рассматриваются как defects.

MUST:

- semantic HTML прежде ARIA;
- `<button>` для действия, `<a>` для navigation;
- label/input association;
- keyboard accessibility;
- visible focus;
- image alt semantics;
- heading structure;
- не вешать click-only behavior на `<div>`;
- live region только при реальной динамической необходимости.

Нельзя suppress a11y warning без documented reason.

## 9. DOM escape hatches

- `{@html}` запрещён для недоверенного content;
- `bind:this` использовать только для imperative integration;
- action/use/direct DOM API должен иметь lifecycle cleanup;
- не искать DOM глобально из component, если element можно получить локально.

## 10. Styling

Svelte scoped styles хороши для component-specific CSS. Общие tokens/base styles — в shared CSS entry.

Не использовать `:global(...)` без необходимости; это пробивает isolation. Global selector должен быть узким и объяснимым.

CSS rule source — `.agents/codestyle/css.md`.

## 11. Forms

- form state typed;
- native constraint semantics использовать, где подходят;
- server/runtime validation всё равно обязательна;
- loading/submitting state предотвращает accidental double submit;
- error должен быть связан с field и доступен assistive tech.

## 12. Async UI

Каждая async operation должна иметь понятные состояния: idle/loading/success/error. Не оставлять rejection необработанным.

При component destruction/cancellation учитывать stale response, если request может закончиться позже component lifecycle.

## 13. Lists

Keyed each block использовать, когда identity элементов важна для DOM/component lifecycle. Key должен быть стабильным domain identifier, не случайным значением при render.

## 14. Snippets/components

Переиспользование строить по semantic UI abstraction. Не создавать component на каждую пару тегов. Для повторяющейся structure/behavior snippets/components выбирать по текущему Svelte API и читаемости.

## 15. Performance

Не оптимизировать до измерения. Но избегать:

- expensive computation в template при каждом update, если это derived state;
- огромного global reactive state;
- duplicate subscriptions;
- effects, которые пишут state и вызывают cascading reruns;
- rendering тысяч nodes без virtualization strategy.

## 16. TypeScript

`<script lang="ts">` MUST для нового component logic. Настройки `isolatedModules`/`verbatimModuleSyntax` должны сохраняться. Svelte docs рекомендуют `svelte-check` для CI/type checking; Vite сам type check не выполняет.

## 17. Human text

Нелокализуемые diagnostics/comments — только на русском. Пользовательские UI strings в текущем boilerplate русские, но при появлении требований multilingual UI MUST быть вынесены в locale layer.

## 18. Testing strategy

При добавлении сложного frontend behavior SHOULD добавить component/unit tests (например Vitest + Testing Library) как отдельное осознанное расширение. Не добавлять test framework пустым dependency, пока UI демонстрационный.

Critical business logic не должна существовать только внутри `.svelte` markup и оставаться нетестируемой.

## 19. Checklist

- [ ] Runes, не legacy syntax для нового кода.
- [ ] Derived state через `$derived`, не sync effect.
- [ ] Effects имеют external reason + cleanup.
- [ ] Props typed.
- [ ] A11y warnings отсутствуют.
- [ ] `{@html}` отсутствует или безопасно обоснован.
- [ ] CSS scoped/global boundary осознан.
- [ ] Async errors/lifecycle учтены.
- [ ] `svelte-check` проходит.

## Источники

- Svelte 5 overview: https://svelte.dev/docs/svelte/overview
- Runes: https://svelte.dev/docs/svelte/what-are-runes
- TypeScript in Svelte: https://svelte.dev/docs/svelte/typescript
- Accessibility warnings: https://svelte.dev/docs/svelte/compiler-warnings
- Svelte Vite plugin: https://github.com/sveltejs/vite-plugin-svelte
