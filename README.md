# docker-cli

Console utility for managing local Docker environments.

## Commands

- `bin/docker-cli start` creates `~/.config/docker-cli/compose/system` when needed, writes the default system compose files if absent, and runs `docker compose up -d` for the `docker-cli` project.
- `bin/docker-cli stop` uses the same compose file and runs `docker compose down --remove-orphans`, removing the shared `docker-cli` network.

The generated `.env` contains `BASE_HOST=local.kubehut.top` by default. System services use `system` as their project segment, so Dockge is published as `dockge.system.local.kubehut.top` when that default base host is kept.

## Packaging

Run:

```bash
php -d phar.readonly=0 scripts/build-phar.php
```

This produces `build/docker-cli.phar`.
