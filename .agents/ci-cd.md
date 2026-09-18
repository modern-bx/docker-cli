# CI/CD: quality, PHAR build и артефакты

## 1. Текущий scope

CI делает проверку и сборку. **Release/publish/deploy пока не реализуются.**

Успешный pipeline должен создать immutable CI artifacts:

- `acme-cli.phar`;
- `acme-cli.phar.sha256`.

## 2. Каноническая реализация

Вся логика находится в `.ci/scripts/*` и `.ci/Dockerfile`. Конфиги конкретных repository systems — тонкие adapters.

Это MUST сохраняться: нельзя реализовать «особый GitHub build» и другой GitLab build, которые дают разные результаты.

Канонический сценарий:

```bash
sh .ci/scripts/run-all.sh
```

Локально через Docker:

```bash
sh .ci/scripts/docker-run.sh
```

## 3. Этапы

`run-all.sh` выполняет:

1. bootstrap dependencies;
2. `composer quality`;
3. Vite production build;
4. Box + PHP-Scoper PHAR build;
5. PHAR smoke/isolation/integrity/content checks;
6. повторную сборку + SHA-256 comparison;
7. копирование PHAR/checksum в `artifacts/`.

## 4. CI environment

`.ci/Dockerfile` фиксирует major runtime/toolchain:

- PHP 8.4;
- Node 22.13+;
- Composer 2;
- extensions, нужные runtime/test tools.

Exact transitive versions фиксируются lock-файлами конкретного проекта.

## 5. GitHub Actions — основной профиль

`.github/workflows/ci.yml`:

- `push`, `pull_request`, manual dispatch;
- read-only `contents` permission;
- concurrency cancellation для устаревших runs;
- checkout без persisted credentials;
- official actions pinned полными commit SHA;
- PHAR/checksum загружаются через artifact action только после success;
- retention ограничен.

Protected branch SHOULD требовать GitHub job `Проверки и PHAR` как required check.

Third-party GitHub Action MUST быть pinned полным SHA. Tag `@v4` не является immutable supply-chain pin.

## 6. GitLab CI/CD

`.gitlab-ci.yml` использует Docker-in-Docker и публикует `artifacts/` после успешной job. Protected branch/MR rules SHOULD требовать pipeline success.

Runner должен быть доверенным для Docker privileged/service model.

## 7. Bitbucket Pipelines

`bitbucket-pipelines.yml` запускает тот же Docker build для default branches и pull requests; `artifacts/**` сохраняется как pipeline artifact.

## 8. Azure Pipelines

`azure-pipelines.yml` запускается на hosted Ubuntu runner.

Для **Azure Repos Git** YAML `pr:` не является механизмом PR validation. Проверку pull request надо привязать через Branch Policies → Build Validation.

PublishPipelineArtifact сохраняет каталог `artifacts`.

## 9. CircleCI

Machine executor используется ради Docker daemon. Artifact store получает `artifacts/`.

Если organization запрещает machine executors, адаптировать runner architecture, но не менять внутренний `run-all.sh` contract.

## 10. Gitea Actions

Runner MUST иметь рабочий Docker daemon/socket и права для build/run. Gitea workflow синтаксически похож на GitHub Actions, но не следует считать все GitHub-specific features автоматически совместимыми.

## 11. Forgejo Actions

Forgejo workflow отдельный. Forgejo прямо позиционирует Actions как familiar, но не гарантирует полную GitHub compatibility. Используются Forgejo-hosted checkout/upload actions.

Runner label и Docker access настраиваются под конкретную Forgejo installation.

## 12. Jenkins

`Jenkinsfile` предполагает node с label `docker` и доступным Docker CLI/daemon. PHAR/checksum архивируются через `archiveArtifacts` с fingerprint.

## 13. Secrets

Текущий CI не требует production secrets. Так и должно оставаться, пока pipeline только проверяет/собирает.

Нельзя добавлять production credential в PR job, выполняющую недоверенный fork code.

Если позже появятся signing/release credentials:

- отдельная protected/tag/manual stage;
- минимальные permissions;
- secrets не доступны pull request из fork;
- artifact передаётся из уже прошедшего build, а не пересобирается;
- audit/provenance policy описывается отдельно.

## 14. Artifact boundary

Artifact из CI — результат уже проверенного source revision. Последующая release/deploy stage в будущем MUST потреблять именно этот PHAR/hash.

Не делать:

```text
quality -> success
release -> git checkout заново -> composer update -> новый build
```

Это создаёт другой artifact.

## 15. Reproducibility

CI дважды строит PHAR и сравнивает SHA-256. Для одинакового working tree/dependency locks/toolchain bytes должны совпасть.

Важные источники nondeterminism:

- volatile build timestamps;
- random values;
- абсолютные paths;
- varying dependency resolution без lock;
- unordered generated content;
- embedding CI run ID/date в application files.

## 16. Caches

Dependency/cache optimization MAY быть добавлена позже. Cache не должен менять correctness. Key должен учитывать lock files/toolchain. Нельзя кэшировать `artifacts` как substitute для build.

## 17. PR/merge policy

Рекомендуется:

- protected main/default branch;
- required CI status;
- запрет direct push для production repository;
- review хотя бы одним человеком для significant changes;
- stale approvals reset при materially changed code, если платформа поддерживает;
- no merge при red/flaky CI.

## 18. Human-readable CI text

Собственные step names, shell diagnostics и комментарии — на русском. Названия внешних products/actions/API не переводятся.

## 19. Проверка CI changes

При изменении CI MUST проверить:

- YAML syntax всех adapters;
- shell syntax scripts;
- Dockerfile builds в среде с Docker;
- artifacts реально появляются;
- PHAR запускается;
- PHAR запускается при заранее объявленном конфликтующем классе `Symfony\Component\Console\Application`, подтверждая реальную изоляцию vendor namespace;
- внутри PHAR реально присутствуют prefixed namespaces как Symfony, так и собственного приложения;
- failure quality step действительно делает pipeline red;
- no release side effect.

## 20. Источники

- GitHub Actions security: https://docs.github.com/actions/security-for-github-actions/security-guides/security-hardening-for-github-actions
- GitHub artifacts: https://docs.github.com/actions/using-workflows/storing-workflow-data-as-artifacts
- GitLab CI/CD: https://docs.gitlab.com/ci/
- Bitbucket Pipelines: https://support.atlassian.com/bitbucket-cloud/docs/get-started-with-bitbucket-pipelines/
- Azure Pipelines: https://learn.microsoft.com/azure/devops/pipelines/
- Azure branch policies: https://learn.microsoft.com/azure/devops/repos/git/branch-policies
- CircleCI: https://circleci.com/docs/
- Gitea Actions: https://docs.gitea.com/usage/actions/overview
- Forgejo Actions: https://forgejo.org/docs/latest/user/actions/
- Jenkins Pipeline: https://www.jenkins.io/doc/book/pipeline/
