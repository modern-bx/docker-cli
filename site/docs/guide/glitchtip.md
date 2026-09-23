# GlitchTip

[GlitchTip](https://glitchtip.com/) — совместимая с Sentry система наблюдаемости с открытым исходным кодом.
Она принимает события через Sentry SDK и позволяет в одном интерфейсе:

- группировать исключения и ошибки в issues;
- принимать сообщения приложения и структурированные логи;
- просматривать breadcrumbs, пользователя, tags и дополнительный контекст ошибки;
- собирать транзакции и spans для анализа производительности;
- проверять доступность HTTP-адресов и heartbeat-задач.

Системный стек публикует интерфейс по адресу `https://glitchtip.<BASE_HOST>`. GlitchTip работает независимо от
зарегистрированных проектов docker-cli: каждый наблюдаемый проект надо один раз создать в самом GlitchTip.

Данные GlitchTip хранятся в системном каталоге compose: PostgreSQL — в `data/glitchtip-postgres`, загруженные
файлы — в `data/glitchtip-uploads`, данные Valkey — в `data/glitchtip-valkey`. Поэтому они остаются на хосте при
пересоздании контейнеров и доступны для резервного копирования вместе с остальными системными данными.

## Первый запуск

1. Откройте `https://glitchtip.<BASE_HOST>` и войдите с `GLITCHTIP_ADMIN_EMAIL` и
   `GLITCHTIP_ADMIN_PASSWORD` из системного `.env`.
2. Создайте organization и project, выбрав подходящую PHP-платформу.
3. Скопируйте DSN со страницы проекта. Позже его можно найти в настройках проекта.
4. Сохраните DSN в `.env` приложения, например как `SENTRY_DSN`. Не добавляйте его непосредственно в исходный
   код.
5. Отправьте тестовое событие и убедитесь, что оно появилось в разделе Issues или Logs.

DSN имеет примерно такой вид:

```dotenv
SENTRY_DSN=https://public-key@glitchtip.example.test/1
```

Контейнеры проектов входят в сеть `docker-cli`, поэтому обращаются к тому же HTTPS-адресу GlitchTip, что и
браузер. Хост и ключ необходимо брать из интерфейса, а не собирать DSN вручную.

## Рекомендуемые PHP-пакеты

GlitchTip реализует Sentry-совместимый ingest API, поэтому для PHP используются официальные Sentry SDK:

- [`sentry/sentry`](https://packagist.org/packages/sentry/sentry) — базовый SDK для PHP-приложений;
- [`sentry/sentry-symfony`](https://packagist.org/packages/sentry/sentry-symfony) — интеграция с Symfony;
- [`sentry/sentry-laravel`](https://packagist.org/packages/sentry/sentry-laravel) — интеграция с Laravel;
- [`monolog/monolog`](https://packagist.org/packages/monolog/monolog) — если приложение уже пишет логи через
  Monolog; обработчики входят в `sentry/sentry`.

Официальные инструкции GlitchTip также доступны для
[обычного PHP](https://glitchtip.com/sdkdocs/php),
[Symfony](https://glitchtip.com/sdkdocs/php-symfony),
[Laravel](https://glitchtip.com/sdkdocs/php-laravel) и
[Monolog](https://glitchtip.com/sdkdocs/php-monolog).

Для примеров структурированных логов нужен `sentry/sentry` версии 4.15 или новее:

```bash
composer require 'sentry/sentry:^4.15'
```

Framework-пакеты уже зависят от базового SDK. Не устанавливайте одновременно несколько интеграций без
необходимости и проверяйте совместимость актуальной версии пакета с версией PHP и framework проекта.

## Базовая настройка PHP

Инициализируйте SDK как можно раньше, до выполнения прикладного кода:

```php
<?php

declare(strict_types=1);

require __DIR__ . "/vendor/autoload.php";

Sentry\init([
    "dsn" => getenv("SENTRY_DSN") ?: null,
    "environment" => getenv("APP_ENV") ?: "development",
    "release" => getenv("APP_RELEASE") ?: null,
    "traces_sample_rate" => 0.1,
    "enable_logs" => true,
]);
```

`traces_sample_rate=0.1` отправляет примерно 10% транзакций. Для малонагруженного тестового проекта можно
временно использовать `1.0`, но в постоянно работающем приложении лучше начинать с `0.05`–`0.1`.

## Исключения и сообщения

Необработанные исключения framework-интеграции перехватывают автоматически. Если исключение было обработано
приложением, его можно отправить явно:

```php
use function Sentry\captureException;
use function Sentry\captureMessage;

try {
    $billing->charge($order);
} catch (Throwable $exception) {
    captureException($exception);
    throw $exception;
}

captureMessage("Повторная синхронизация каталога заняла слишком много времени");
```

`captureMessage()` создаёт issue, поэтому не используйте его для каждого штатного сообщения. Для потока
информационных записей предназначены структурированные логи.

## Структурированные логи

SDK группирует логи в буфер. В короткоживущем PHP-процессе зарегистрируйте отправку буфера при завершении:

```php
use function Sentry\logger;

register_shutdown_function(static function (): void {
    logger()->flush();
});

logger()->info(
    "Заказ %s передан в доставку",
    [(string) $orderId],
    ["order.id" => $orderId, "delivery.service" => "courier"],
);

logger()->error(
    "Платёж %s отклонён",
    [(string) $paymentId],
    ["payment.id" => $paymentId, "payment.provider" => "bank"],
);
```

Первый массив содержит значения для шаблона сообщения, второй — индексируемые атрибуты. Не передавайте в них
пароли, токены, cookies, полные платёжные реквизиты и другие персональные или секретные данные.

### Monolog

Если проект использует Monolog, подключите обработчик структурированных логов:

```php
use Monolog\Logger;
use Sentry\Logs\LogLevel;
use Sentry\Monolog\LogsHandler;

$logger = new Logger("application");
$logger->pushHandler(new LogsHandler(LogLevel::info()));

$logger->info("Импорт завершён", ["rows" => $rowCount]);
$logger->error("Импорт завершился с ошибкой", ["file" => $fileName]);
```

`LogsHandler` не превращает исключения в issues. Исключения отправляйте через framework-интеграцию или
`captureException()`.

## Контекст, пользователь и breadcrumbs

Контекст помогает воспроизвести ошибку, а tags позволяют фильтровать события:

```php
use Sentry\Breadcrumb;
use Sentry\State\Scope;
use function Sentry\addBreadcrumb;
use function Sentry\configureScope;

configureScope(static function (Scope $scope) use ($userId, $tenant): void {
    $scope->setUser(["id" => (string) $userId]);
    $scope->setTag("tenant", $tenant);
    $scope->setContext("runtime", [
        "queue" => "imports",
        "attempt" => 2,
    ]);
});

addBreadcrumb(new Breadcrumb(
    Breadcrumb::LEVEL_INFO,
    Breadcrumb::TYPE_DEFAULT,
    "catalog",
    "Начат импорт каталога",
    ["source" => "supplier"],
));
```

Breadcrumb не является отдельным событием: он прикрепится к следующей ошибке или транзакции. Не используйте
значения с высокой уникальностью в tags — идентификаторы запросов и большие структуры лучше хранить в context.

## Производительность

Framework-интеграции автоматически создают транзакции для поддерживаемых HTTP-запросов и команд. В обычном PHP
коде транзакцию и дочерний span можно создать вручную:

```php
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionContext;
use function Sentry\startTransaction;

$transactionContext = TransactionContext::make()
    ->setName("catalog.import")
    ->setOp("task");
$transaction = startTransaction($transactionContext);
SentrySdk::getCurrentHub()->setSpan($transaction);

try {
    $spanContext = SpanContext::make()
        ->setOp("db.query")
        ->setDescription("Загрузка товаров");
    $span = $transaction->startChild($spanContext);

    try {
        $products = $repository->loadForImport();
    } finally {
        $span->finish();
    }
} finally {
    $transaction->finish();
    SentrySdk::getCurrentHub()->setSpan(null);
}
```

Перед ручной инструментацией проверьте, не создаёт ли framework уже такую транзакцию: дубли ухудшают статистику.

## Uptime и heartbeat

HTTP-монитор создаётся в интерфейсе GlitchTip и не требует PHP SDK. Для периодической команды удобен heartbeat:
после создания монитора GlitchTip покажет уникальный URL. Вызовите его только после успешного завершения задачи:

```php
$heartbeatUrl = getenv("GLITCHTIP_HEARTBEAT_URL");
if (is_string($heartbeatUrl) && $heartbeatUrl !== "") {
    $client->request("GET", $heartbeatUrl);
}
```

Если heartbeat не придёт за настроенный интервал, монитор перейдёт в состояние Down. URL heartbeat является
секретом и должен храниться в переменной окружения.

## Что проверить после подключения

1. Исключение появилось в Issues и содержит корректные `environment` и `release`.
2. Записи `logger()` или Monolog видны в Logs.
3. Транзакции появились в Performance, а sampling не создаёт лишнюю нагрузку.
4. Alerts проекта отправляют уведомления нужной команде.
5. В события не попадают пароли, токены и персональные данные.

GlitchTip не является системой долговременного хранения всех application logs. Отправляйте диагностически полезные
события, настройте разумный sampling и периодически контролируйте объём базы PostgreSQL.
