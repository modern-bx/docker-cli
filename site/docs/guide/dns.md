# DNS и браузеры

## Настройка dnsdock на Linux

`dnsdock` публикует DNS на адресе `172.17.0.1:53/udp`, который доступен с хоста через стандартный Docker bridge. Чтобы Linux-система резолвила имена вида `*.dev.example.com` через dnsdock, добавьте для базового домена отдельный DNS-маршрут.

Ниже описан способ через drop-in для `systemd-resolved`. Он проверялся только на Ubuntu 24.04.

Создайте drop-in:

```bash
sudo mkdir -p /etc/systemd/resolved.conf.d
sudo tee /etc/systemd/resolved.conf.d/docker-cli.conf >/dev/null <<'EOF_DROPIN'
[Resolve]
DNS=172.17.0.1
Domains=~dev.example.com
EOF_DROPIN
```

Перезапустите `systemd-resolved` и сбросьте кеш:

```bash
sudo systemctl restart systemd-resolved
sudo resolvectl flush-caches
```

Проверка системного резолва:

```bash
resolvectl status
resolvectl query dockhand.dev.example.com
```

Ожидаемо `resolvectl query dockhand.dev.example.com` должен вернуть IP контейнера `traefik`, а не публичный wildcard-адрес из Cloudflare.

Для прямой проверки dnsdock используйте:

```bash
dig @172.17.0.1 dockhand.dev.example.com
```

Во всех примерах замените `dev.example.com` на своё значение `BASE_HOST`. Если домен ещё не подготовлен, начните с раздела [Настройка домена в Cloudflare](./cloudflare.md).

## Firefox

Если `resolvectl query dockhand.dev.example.com` возвращает IP Traefik, но Firefox открывает другой хост или публичный IP, проверьте:

- расширения VPN/proxy, например FoxyProxy, SwitchyOmega, корпоративные VPN-расширения и похожие инструменты;
- настройки `Settings → General → Network Settings`: для диагностики выберите `No proxy`;
- `about:config`: `network.proxy.type` должен быть `0` для режима `No proxy`;
- `about:config`: `network.proxy.socks_remote_dns` временно поставьте в `false`, если используется SOCKS;
- `about:config`: `network.trr.mode = 5` отключает Firefox DNS-over-HTTPS/TRR;
- `about:config`: в `network.trr.excluded-domains` можно добавить `dev.example.com`;
- `about:networking#dns`: нажмите `Clear DNS Cache` после изменения настроек.

Если Firefox установлен как snap, сравните резолв внутри и снаружи snap:

```bash
getent hosts dockhand.dev.example.com
snap run --shell firefox
getent hosts dockhand.dev.example.com
cat /etc/resolv.conf
```

Если внутри snap возвращается другой IP, проблема в DNS-окружении snap. Для диагностики можно проверить обычную deb/tarball-сборку Firefox с чистым профилем.

## Chrome / Chromium

Если Chrome или Chromium открывает не Traefik, проверьте:

- расширения VPN/proxy и системные VPN-клиенты;
- `Settings → System → Open your computer's proxy settings`: убедитесь, что браузер не уходит через неожиданный proxy;
- `Settings → Privacy and security → Security → Use secure DNS`: временно отключите Secure DNS или выберите системный DNS;
- `chrome://net-internals/#dns`: нажмите `Clear host cache`;
- `chrome://net-internals/#sockets`: нажмите `Flush socket pools`.

Для Chromium в snap выполните аналогичную проверку snap-окружения:

```bash
getent hosts dockhand.dev.example.com
snap run --shell chromium
getent hosts dockhand.dev.example.com
cat /etc/resolv.conf
```
