# Настройка домена в Cloudflare

Для работы HTTPS-маршрутов нужен ваш собственный домен в Cloudflare. Не используйте домены из примеров как готовую настройку: выберите подконтрольную вам зону и укажите её в `BASE_HOST`.

## Что подготовить

1. Домен или поддомен, DNS-зона которого обслуживается Cloudflare.
2. Cloudflare API token с правом редактировать DNS выбранной зоны.
3. Email для Let's Encrypt уведомлений.

## DNS-запись

Создайте wildcard-запись для выбранного домена. Например, если вы выбрали `dev.example.com`, нужна запись:

```text
*.dev.example.com
```

Она должна указывать на адрес, который будет использоваться как внешний fallback. Локально имена всё равно будут переопределяться через `dnsdock` и системный DNS-маршрут.

## Cloudflare API token

В Cloudflare создайте API token с минимальными правами:

- `Zone → Zone → Read` для выбранной зоны;
- `Zone → DNS → Edit` для выбранной зоны.

Значение токена будет использовать Traefik для ACME DNS challenge.

## Параметры `.env`

После `bin/docker-cli config:init` откройте файл:

```text
~/.config/docker-cli/compose/system/.env
```

Заполните параметры:

```dotenv
BASE_HOST=dev.example.com
CLOUDFLARE_DNS_API_TOKEN=<cloudflare-api-token>
ACME_EMAIL=admin@example.com
```

`BASE_HOST` обязателен. Если он пустой или отсутствует, генерация OpenResty-хостов завершается ошибкой, чтобы не подставлять чужой домен по умолчанию.

## Дальше

После настройки домена продолжайте с локальным DNS-маршрутом в разделе [DNS и браузеры](./dns.md). Там во всех командах заменяйте `dev.example.com` на значение вашего `BASE_HOST`.
