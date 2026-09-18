# Vite: frontend build contract

## 1. Роль Vite

Vite — единственный frontend bundler проекта. Source: `frontend/`; production output: `frontend/dist/`. PHAR build потребляет только production output.

## 2. Node version

Целевой baseline — Node.js 22.13+ LTS; Node.js 24+ также поддерживается. CI использует Node 22. Local/CI version drift недопустим; `package-lock.json` после инициализации MUST быть закоммичен.

## 3. Config

`vite.config.ts`:

- root = `frontend`;
- `base: './'`, потому что bundled UI может раздаваться с неизвестного URL base;
- Svelte plugin;
- `outDir: 'dist'`;
- `emptyOutDir: true`;
- manifest включён;
- production sourcemaps по умолчанию выключены, чтобы не раздувать PHAR и не раскрывать source случайно.

Sourcemaps MAY быть включены отдельным решением с пониманием artifact/security cost.

## 4. Type checking

Vite transpiles TypeScript, но не проверяет types. Поэтому `npm run build` не заменяет `npm run typecheck`.

CI MUST выполнить quality до build.

## 5. Environment variables

Всё `VITE_*`, попавшее в client bundle, считается публичным. Secrets NEVER передавать frontend через Vite env.

Если frontend должен получить runtime config от PHP server, отдавать отдельный safe endpoint/bootstrap payload, а не bake secret в bundle.

## 6. Static assets

Импортировать assets через module graph, когда это часть component. `public/` использовать только для файлов, которым нужен exact path/name и не нужен hashing.

Не помещать environment-specific config в hashed asset.

## 7. Manifest

Vite manifest является build integrity marker и может использоваться server integration. Boilerplate проверяет его наличие внутри PHAR.

## 8. Dependencies

Не добавлять frontend dependency, если browser/platform/Svelte решает задачу нативно. Перед крупной library оценить:

- bundle size;
- maintenance;
- security;
- tree shaking;
- SSR/browser assumptions;
- license.

## 9. Build determinism

Build не должен включать `Date.now()`, random build id, machine absolute path или volatile environment text без осознанной причины. Это ломает reproducible PHAR.

## 10. Dev server

Vite dev server — только development. Production `serve` command раздаёт уже собранный `frontend/dist`; он не запускает Vite/npm.

## 11. Svelte preprocess

Используется `vitePreprocess({ script: true })` для TS preprocess, соответствующий рекомендованной Vite/Svelte integration.

## 12. Checklist

- [ ] Source не зависит от production Vite server.
- [ ] `base` сохраняет portable paths.
- [ ] Type check отдельный от build.
- [ ] Нет secrets в `VITE_*`.
- [ ] dist не коммитится.
- [ ] manifest создаётся.
- [ ] build воспроизводим.

## Источники

- Vite guide: https://vite.dev/guide/
- Vite features/TypeScript: https://vite.dev/guide/features.html#typescript
- Vite backend integration/manifest: https://vite.dev/guide/backend-integration.html
- Vite shared options/base: https://vite.dev/config/shared-options.html#base
