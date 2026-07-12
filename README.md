# docker-cli

Консольная утилита для управления локальными Docker-окружениями.

## Команды

Перед первым запуском создайте конфигурацию:

```bash
bin/docker-cli init
```

Команда создаёт `~/.config/docker-cli/compose/system`, а также файлы `.env` и `compose.yaml`, если они отсутствуют. Уже существующие файлы не перезаписываются. Если шаблоны в репозитории обновились, а конфиг уже был создан ранее, перенесите изменения в `~/.config/docker-cli/compose/system/compose.yaml` вручную.

После проверки параметров в `.env` можно запускать и останавливать системное окружение:

```bash
bin/docker-cli start
bin/docker-cli stop
```

`start` выполняет `docker compose up -d` для проекта `docker-cli`, а `stop` выполняет `docker compose down --remove-orphans` и удаляет общую сеть `docker-cli`. Если `.env` или `compose.yaml` отсутствуют, команды завершаются понятной ошибкой и предлагают выполнить `docker-cli init`.

## Базовые сервисы

Системный compose-файл запускает:

- `dnsdock` для автоматических DNS-имён контейнеров;
- `traefik` для HTTPS-доступа и выпуска сертификатов через Cloudflare DNS challenge;
- `dockge` как веб-интерфейс мониторинга и управления compose-стеками.

По умолчанию `BASE_HOST=local.kubehut.top`, поэтому Dockge будет доступен по адресу:

```text
https://dockge.local.kubehut.top
```

Язык сообщений CLI задаётся параметром `APP_LOCALE` в сгенерированном `.env`. По умолчанию используется русский (`ru`).

## Настройка dnsdock на Linux

`dnsdock` публикует DNS на адресе `172.17.0.1:53/udp`, который доступен с хоста через стандартный Docker bridge. Чтобы Linux-система резолвила имена вида `*.local.kubehut.top` через dnsdock, добавьте для базового домена отдельный DNS-маршрут.

Ниже описан способ через drop-in для `systemd-resolved`. Он проверялся только на Ubuntu 24.04.

Создайте drop-in:

```bash
sudo mkdir -p /etc/systemd/resolved.conf.d
sudo tee /etc/systemd/resolved.conf.d/docker-cli.conf >/dev/null <<'EOF'
[Resolve]
DNS=172.17.0.1
Domains=~local.kubehut.top
EOF
```

Перезапустите `systemd-resolved` и сбросьте кеш:

```bash
sudo systemctl restart systemd-resolved
sudo resolvectl flush-caches
```

Проверка системного резолва:

```bash
resolvectl status
resolvectl query dockge.local.kubehut.top
```

Ожидаемо `resolvectl query dockge.local.kubehut.top` должен вернуть IP контейнера `traefik`, а не публичный wildcard-адрес из Cloudflare. Для прямой проверки dnsdock используйте:

```bash
dig @172.17.0.1 dockge.local.kubehut.top
```

Если используется другой `BASE_HOST`, замените `local.kubehut.top` в drop-in и командах проверки на своё значение.

## Проверка браузеров

### Firefox

Если `resolvectl query dockge.local.kubehut.top` возвращает IP Traefik, но Firefox открывает другой хост или публичный IP, проверьте:

- расширения VPN/proxy, например FoxyProxy, SwitchyOmega, корпоративные VPN-расширения и похожие инструменты;
- настройки `Settings → General → Network Settings`: для диагностики выберите `No proxy`;
- `about:config`: `network.proxy.type` должен быть `0` для режима `No proxy`;
- `about:config`: `network.proxy.socks_remote_dns` временно поставьте в `false`, если используется SOCKS;
- `about:config`: `network.trr.mode = 5` отключает Firefox DNS-over-HTTPS/TRR;
- `about:config`: в `network.trr.excluded-domains` можно добавить `local.kubehut.top`;
- `about:networking#dns`: нажмите `Clear DNS Cache` после изменения настроек.

Если Firefox установлен как snap, сравните резолв внутри и снаружи snap:

```bash
getent hosts dockge.local.kubehut.top
snap run --shell firefox
getent hosts dockge.local.kubehut.top
cat /etc/resolv.conf
```

Если внутри snap возвращается другой IP, проблема в DNS-окружении snap. Для диагностики можно проверить обычную deb/tarball-сборку Firefox с чистым профилем.

### Chrome / Chromium

Если Chrome или Chromium открывает не Traefik, проверьте:

- расширения VPN/proxy и системные VPN-клиенты;
- `Settings → System → Open your computer's proxy settings`: убедитесь, что браузер не уходит через неожиданный proxy;
- `Settings → Privacy and security → Security → Use secure DNS`: временно отключите Secure DNS или выберите системный DNS;
- `chrome://net-internals/#dns`: нажмите `Clear host cache`;
- `chrome://net-internals/#sockets`: нажмите `Flush socket pools`;
- для Chromium в snap выполните аналогичную проверку snap-окружения:

```bash
getent hosts dockge.local.kubehut.top
snap run --shell chromium
getent hosts dockge.local.kubehut.top
cat /etc/resolv.conf
```

## Упаковка в PHAR

```bash
php -d phar.readonly=0 scripts/build-phar.php
```

Команда создаёт `build/docker-cli.phar`.
