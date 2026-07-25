# Xdebug

Образ `php-fpm-8.2` устанавливает расширение Xdebug и включает только режим пошаговой отладки. Профайлинг, трассировка, coverage и остальные режимы не включаются.

## Порты проектов

Для браузера используется единый trigger cookie:

```text
XDEBUG_TRIGGER=docker-cli
```

Каждый зарегистрированный проект получает отдельный порт IDE. Это позволяет держать несколько проектов открытыми в PhpStorm одновременно: cookie одна и та же, а входящие debug-соединения приходят на разные порты.

При `docker-cli project:up` в `~/.config/docker-cli/projects/<project>/project.yaml` сохраняется вычисленная настройка:

```yaml
data:
  project:
    xdebug:
      client_port: 9004
```

Алгоритм не использует стандартный порт Xdebug `9003`: первый кандидат — `9004`. Если такой порт уже записан у любого зарегистрированного проекта, кандидат увеличивается на `1`, после чего список проектов проверяется заново. Первый порт, которого нет ни в одном `project.yaml`, сохраняется за новым проектом.

Команда пересборки портов:

```bash
docker-cli config:init --rebuild
```

Она пересобирает порты для всех зарегистрированных проектов в детерминированном порядке и затем заново генерирует OpenResty-конфиги. OpenResty при генерации vhost-а подставляет этот порт в FastCGI-параметр `PHP_VALUE`, поэтому browser-request конкретного проекта подключается именно к своему порту IDE.

## Активация из браузера

Xdebug стартует только по trigger-у. Для browser-сценариев установите cookie:

```text
XDEBUG_TRIGGER=docker-cli
```

Удобнее всего сделать это расширением браузера для Xdebug. Значение cookie одинаковое для всех проектов. Разделение проектов выполняется не cookie, а портом `xdebug.client_port`, который docker-cli хранит в конфиге проекта и передаёт в PHP-FPM через OpenResty.

## Активация из консоли внутри контейнера

При входе в PHP-FPM контейнер через интерактивный shell docker-cli подключает `/etc/profile.d/docker-cli-xdebug.sh`. Скрипт ищет вверх от текущей директории файл `.docker-cli/project.yaml`, по имени проекта читает `~/.config/docker-cli/projects/<project>/project.yaml` и выставляет:

```bash
XDEBUG_CONFIG="client_host=host.docker.internal client_port=<порт проекта> idekey=PHPSTORM"
```

Типовой сценарий:

```bash
docker exec -it docker-cli-php-fpm-8.2 bash
cd /home/user/projects/my-project
export XDEBUG_TRIGGER=docker-cli
php bin/console app:command
```

Если после входа в контейнер вы меняете директорию командой `cd`, wrapper обновляет `XDEBUG_CONFIG` под новый проект.

Для запуска без интерактивного shell можно задать переменные явно:

```bash
docker exec -e XDEBUG_TRIGGER=docker-cli \
  -e 'XDEBUG_CONFIG=client_host=host.docker.internal client_port=9004 idekey=PHPSTORM' \
  docker-cli-php-fpm-8.2 php /home/user/projects/my-project/bin/console app:command
```

## Параметры `zz-xdebug.ini`

Файл `config/php-fpm-8.2/php/conf.d/zz-xdebug.ini` содержит только настройки, необходимые для пошаговой отладки:

- `xdebug.mode=debug` — включает только step debugger.
- `xdebug.start_with_request=trigger` — Xdebug стартует только при наличии trigger-а.
- `xdebug.trigger_value=docker-cli` — ограничивает допустимое значение trigger-а.
- `xdebug.client_host=host.docker.internal` — адрес IDE с точки зрения контейнера.
- `xdebug.client_port=9003` — базовый fallback Xdebug; проектные запуски используют выделенные порты.
- `xdebug.idekey=PHPSTORM` — IDE key для PhpStorm.
- `xdebug.log_level=0` — отключает подробный лог Xdebug.

Само расширение подключается стандартным ini-файлом, который создаёт `docker-php-ext-enable xdebug` при сборке образа. `zz-xdebug.ini` не содержит `zend_extension`, чтобы не загрузить Xdebug дважды.

## Настройка PhpStorm

1. Откройте `Settings/Preferences → PHP → Debug`.
2. В блоке Xdebug добавьте порты всех нужных проектов в поле `Debug port`, например `9004,9005,9006`.
3. Порт конкретного проекта смотрите в `~/.config/docker-cli/projects/<project>/project.yaml` в `data.project.xdebug.client_port`.
4. Включите `Can accept external connections` / нажмите `Start Listening for PHP Debug Connections` на панели PhpStorm.
5. Откройте `Settings/Preferences → PHP → Servers` и создайте server для каждого web-домена проекта:
   - `Name`: удобно указать домен проекта, например `web-my-project.<ваш-домен>`;
   - `Host`: тот же домен, например `web-my-project.<ваш-домен>`;
   - `Port`: `80` — даже если в браузере проект открывается по HTTPS через Traefik;
   - `Debugger`: `Xdebug`;
   - включите `Use path mappings`;
   - локальный корень проекта сопоставьте с тем же абсолютным путём внутри контейнера, если проект лежит в `/home`; для проектов вне `/home` путь внутри контейнера будет `/host/<абсолютный-путь-на-хосте>`.
6. Для браузера установите cookie `XDEBUG_TRIGGER=docker-cli` на домен проекта и обновите страницу.
7. Для CLI войдите в контейнер, перейдите в директорию проекта, выполните `export XDEBUG_TRIGGER=docker-cli` и запустите PHP-скрипт.

Почему в PhpStorm указывается порт `80`, а не `443`: PhpStorm сопоставляет server не с внешним TLS-входом Traefik, а с HTTP-запросом, который после терминации TLS приходит от Traefik в OpenResty. Внутри compose OpenResty слушает обычный HTTP на `80`, а PHP получает FastCGI-параметры уже после проксирования.
