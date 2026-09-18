# CSS: modern responsive styling

## 1. Цели

CSS должен быть локальным, предсказуемым, доступным и устойчивым к росту UI. Сборка идёт через Vite; source находится в `frontend/src` и проверяется Stylelint.

## 2. Naming

Class names — kebab-case. Для component-specific CSS допустима BEM-like схема:

```css
.app-card {}
.app-card__title {}
.app-card--compact {}
```

Не кодировать DOM depth в имени и не использовать визуальные имена вроде `.red-box` для semantic component.

## 3. Specificity

Держать specificity низкой.

Предпочитать single-class selectors. Не строить:

```css
body main .sidebar ul li a.active span {}
```

IDs для styling не использовать. `!important` запрещён без documented integration reason.

## 4. Cascade

Source order и scope должны быть понятны. При росте global CSS MAY использовать `@layer` для explicit cascade architecture. Не применять layer только ради моды на маленьком проекте.

## 5. Global styles

Global CSS ограничить:

- tokens/custom properties;
- box-sizing/basic normalization;
- body typography/background;
- truly shared primitives.

Не делать глобальные rules для всех `button`, `a`, `table`, если это неожиданно меняет component behavior; исключение — осознанная baseline normalization.

## 6. Custom properties

Design tokens — CSS custom properties с semantic names:

```css
:root {
    --space-page: 2rem;
    --radius-card: 1rem;
}
```

Не создавать custom property ради одноразового literal. Имена kebab-case.

## 7. Units

- `rem` для scalable spacing/type-size;
- unitless line-height;
- `%`, `fr`, `minmax`, `clamp` для fluid layout;
- `px` допустим там, где физически нужна fixed raster/1px-like boundary, но не является default everywhere.
- `0` без unit.

## 8. Layout

Flexbox — one-dimensional alignment; Grid — two-dimensional layout. Не использовать positioning/table layout для обычной страницы.

Использовать `gap`, а не margin hacks между siblings, где возможно.

## 9. Responsive

Mobile-first не догма, но layout должен работать от узкого viewport. Избегать fixed widths, создающих horizontal scroll.

Modern tools:

- `clamp()`;
- container queries для reusable components;
- logical properties, если direction-aware behavior важен;
- intrinsic sizing/minmax.

## 10. Accessibility

- visible `:focus-visible`;
- не удалять outline без равноценной замены;
- не полагаться только на цвет;
- учитывать contrast;
- `prefers-reduced-motion` для существенных animations;
- pointer target должен оставаться удобным;
- user zoom/text size не ломает layout.

## 11. Motion

Animation имеет UI reason. Не анимировать `width/height/top/left` в hot interaction, если transform/opacity подходят лучше. Respect reduced motion.

## 12. Dark mode

Использовать `color-scheme` и semantic tokens. Не дублировать весь stylesheet под dark mode. Если theme app-controlled, model it explicitly; если OS-controlled, `prefers-color-scheme` допустим.

## 13. Z-index

Не устраивать гонку `999999`. Ввести documented scale/layers, если появляются popover/modal/toast stacks.

## 14. Assets

- SVG preferred для icons/illustrations, где подходит;
- decorative image semantics не смешивать с content image;
- external font dependency добавлять только осознанно;
- bundle asset URL должен проходить через Vite import/public contract.

## 15. CSS в Svelte

Scoped component style — default для local component. `:global` только для integration/shared contract. Stylelint проверяет style blocks Svelte через `postcss-html`.

Не использовать dynamic generated class string, если обычная class directive/semantic class яснее.

## 16. Nesting

Native CSS nesting MAY использоваться, если target support/build policy гарантирует его. Не делать nesting глубже 2–3 уровней — это ухудшает specificity/readability.

## 17. Unsupported/experimental features

Перед использованием нового CSS feature проверить Baseline/target browsers и Vite processing. Не предполагать, что сборщик автоматически polyfill любую возможность.

## 18. Generated dist

`frontend/dist/**/*.css` не редактировать и не lint вручную.

## 19. Human comments

CSS comments с explanation/TODO — только на русском.

## 20. Checklist

- [ ] Class names kebab-case.
- [ ] Specificity низкая.
- [ ] Нет необоснованного `!important`.
- [ ] Layout responsive.
- [ ] Focus/motion/contrast учтены.
- [ ] Нет global leakage из Svelte component.
- [ ] Stylelint проходит.

## Источники

- MDN CSS: https://developer.mozilla.org/docs/Web/CSS
- web.dev Baseline: https://web.dev/baseline
- Stylelint: https://stylelint.io/
- Svelte styles: https://svelte.dev/docs/svelte/scoped-styles
