# Playwright-сценарии

`docker-cli config:init` копирует встроенные сценарии из `resources/playwright/scripts` в `~/.config/docker-cli/actions/playwright/scripts`. Пользовательские сценарии можно добавлять в любые подпапки внутри этой директории; идентификатор сценария — путь относительно `scripts`, например `bitrix/setup.js` или `bitrix/setup`.

Системный сервис `playwright` использует официальный образ Microsoft `mcr.microsoft.com/playwright:v1.61.0-noble`. Образ содержит браузеры и системные зависимости, а npm-пакет Playwright устанавливается при первом запуске в volume `playwright-node-modules`, потому что официальный образ не поставляет пакет `playwright` для `require('playwright')` в пользовательских скриптах. Версии настраиваются в `~/.config/docker-cli/compose/system/.env`:

```dotenv
PLAYWRIGHT_VERSION=1.61.0
PLAYWRIGHT_IMAGE=mcr.microsoft.com/playwright:v1.61.0-noble
```

`PLAYWRIGHT_VERSION` должен соответствовать версии в теге `PLAYWRIGHT_IMAGE`.

Запуск сценария выполняется только из директории зарегистрированного проекта:

```bash
docker-cli play:run bitrix/setup
```

Чтобы наблюдать за действиями сценария, добавьте `--show`:

```bash
docker-cli play:run --show bitrix/setup
```

Команда запускает графический браузер внутри Playwright-контейнера и открывает локальный noVNC viewer по адресу `http://127.0.0.1:7900`. Устанавливать браузер, X-сервер или VNC-клиент в хост-систему не требуется. Если системная команда открытия URL недоступна, адрес viewer также печатается в терминале и его можно открыть вручную. Порт доступен только через loopback-интерфейс и только пока выполняется сценарий.

Официальный Playwright-образ уже содержит Chromium, Firefox и WebKit. Нужный движок можно выбрать опцией `--browser`; она работает как вместе с `--show`, так и в headless-режиме:

```bash
docker-cli play:run --browser=firefox --show bitrix/setup
docker-cli play:run --browser=webkit bitrix/setup
```

Команда передает в контейнер переменные `PROJECT_NAME`, `PROJECT_ROOT`, `PROJECT_DOCUMENT_ROOT` и `PROJECT_URL`. Встроенный сценарий `bitrix/setup.js` открывает адрес из `PLAYWRIGHT_URL` или `PROJECT_URL`. Для страницы установки редакции «Старт», «Стандарт», «Малый бизнес», «Бизнес» или «Энтерпрайз» он принимает лицензионное соглашение, выбирает вариант лицензионного ключа, заполняет настройки базы данных и администратора значениями из объекта `wizard`, ждет завершения установки базы, создает администратора и после успешного выполнения удаляет `index.php` из корня сайта. Сценарий также распознает мастер «1С-Битрикс24: Корпоративный портал», выполняет общие шаги, проходит дополнительные настройки, включает социальные сервисы и переходит в установленный Битрикс24; при установке Битрикс24 `index.php` не удаляется. Значение `wizard.db.host` выбирает секцию `mysql` или `postgres` из `project.data.databases`.

При регистрации проекта рядом с `.docker-cli/project.yaml` создается директория `.docker-cli/data`. JSON- и YAML-файлы (`.json`, `.yaml`, `.yml`) из нее автоматически читаются перед каждым запуском Playwright. Содержимое каждого файла доступно сценарию как глобальный объект с именем файла без расширения: например, `.docker-cli/data/customer.yaml` становится объектом `customer`. Имена файлов должны быть допустимыми JavaScript-идентификаторами и не могут называться `project`; одинаковые базовые имена у файлов с разными расширениями также не допускаются.

Глобальный объект `project` содержит полное содержимое `~/.config/docker-cli/state/projects/<project>/project.yaml`, включая секции `meta` и `data`.

Параметры сценариев по умолчанию хранятся в `~/.config/docker-cli/actions/playwright/data/<script-path>` и копируются из `resources/playwright/data` командой `config:init`. JSON- и YAML-файлы из папки текущего сценария публикуются как глобальные JS-объекты. Например, `data/bitrix/setup/wizard.yaml` доступен как `wizard`. Файл с тем же базовым именем из `<project-root>/.docker-cli/data` полностью заменяет объект по умолчанию; слияние значений не выполняется. Пустой пароль в штатном `wizard.yaml` заполняется случайным 24-символьным паролем при `config:init`. Маркер `<project-host>` в email администра заменяется полным именем хоста открытой страницы. `wizard.action_delay.headless` задает задержку в режиме без интерфейса, а `wizard.action_delay.visual` — при запуске VNC.

Во время выполнения `bitrix/setup.js` пишет диагностические сообщения в stdout команды и дублирует их в файл внутри проекта: `~/.config/docker-cli/state/projects/<project>/logs/playwright/<script-path>-<timestamp>.log` (например, `bitrix-setup-<timestamp>.log` для `bitrix/setup.js`); после завершения `play:run` дополнительно печатает директорию логов на хосте. В лог попадают путь к лог-файлу, открываемый URL, заголовок страницы и каждое действие сценария. Если после перехода на следующий шаг появляется `.inst-note-block.inst-note-block-red`, его текст выводится как ошибка в stdout и файловый лог, после чего сценарий завершается. Логин и пароли базы данных и администратора не выводятся ни в файловый лог, ни в stdout.


Все файлы `~/.config/docker-cli/actions/playwright/scripts/mixins/*.js` автоматически подключаются перед запуском сценария через Node.js `--require`. Встроенный `mixins/logging.js` публикует объектный helper `dockerCli.logging` с методами `info(message)`, `warn(message)`, `error(message)` и `debug(message)`, которые одновременно пишут сообщение в stdout и в текстовый файл. Каждая строка содержит уровень после времени, например `[2026-07-24T00:00:00.000Z] [INFO] message`; stdout дополнительно окрашивается по уровню, если терминал поддерживает ANSI-цвета.
