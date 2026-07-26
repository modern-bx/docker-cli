# Упаковка в PHAR

Для сборки PHAR-архива выполните команду из корня репозитория:

Полная сборка, включая production-ресурсы административной панели:

```bash
composer build
```

Если панель уже собрана, можно пересобрать только PHAR:

```bash
composer build-phar
```

Команда создаёт файл:

```text
build/docker-cli.phar
```

Сборка не обновляет команду `docker-cli`, которая ранее была установлена в `PATH`. Для проверки только что собранной версии вызывайте PHAR по явному пути:

```bash
build/docker-cli.phar --version
build/docker-cli.phar panel:up
```

Чтобы заменить глобальную версию, узнайте путь к ней с помощью `command -v docker-cli` и скопируйте туда новый `build/docker-cli.phar` с правами на исполнение.
