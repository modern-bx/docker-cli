# Playwright-сценарии

`docker-cli config:init` копирует встроенные сценарии из `resources/playwright/scripts` в `~/.config/docker-cli/playwright/scripts`. Пользовательские сценарии можно добавлять в любые подпапки внутри этой директории; идентификатор сценария — путь относительно `scripts`, например `bitrix/setup.js` или `bitrix/setup`.

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

Команда передает в контейнер переменные `PROJECT_NAME`, `PROJECT_ROOT`, `PROJECT_DOCUMENT_ROOT` и `PROJECT_URL`. Встроенный сценарий `bitrix/setup.js` открывает адрес из `PLAYWRIGHT_URL` или `PROJECT_URL`, ждет загрузку страницы, через 3 секунды выводит заголовок, ждет еще 10 секунд и завершается.

Во время выполнения `bitrix/setup.js` пишет диагностические сообщения в stdout команды и дублирует их в файл внутри проекта: `.docker-cli/playwright/logs/bitrix-setup-<timestamp>.log`; после завершения `play:run` дополнительно печатает директорию логов на хосте. В лог попадают путь к лог-файлу, открываемый URL, заголовок страницы, сообщения `console` из браузера и ошибки страницы.
