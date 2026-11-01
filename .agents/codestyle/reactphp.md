# ReactPHP: long-running/event-loop код

## 1. Основной принцип

Event loop должен оставаться неблокирующим. Любая операция, которая может занять непредсказуемое время синхронно, должна рассматриваться как потенциальная остановка всего процесса.

## 2. HTTP server

Использовать public API `React\Http\HttpServer` + `React\Socket\SocketServer`. По умолчанию listen на loopback (`127.0.0.1`), а публичную экспозицию делать осознанно.

Для production internet-facing deployment SHOULD использовать reverse proxy, который обеспечивает TLS, limits, access logs, buffering/rate limits и зрелую perimeter security.

## 3. Blocking I/O запрещён в hot path

Опасны:

- большие `file_get_contents()`;
- synchronous DNS/network API;
- `sleep()`;
- blocking DB client;
- запуск долгого child process с ожиданием;
- CPU-heavy loop/compression/crypto на event loop;
- чтение огромного файла целиком.

Малые bundled static assets MAY читаться синхронно в простом embedded UI server, но при росте нагрузки/размера следует перейти на stream/caching strategy или отдельный frontend server.

## 4. Promise semantics

- Promise rejection MUST обрабатываться.
- Не создавать detached async work без lifecycle/error handling.
- Не использовать nested promise pyramid; возвращать chain/async abstraction проекта.
- Ошибка одного request не должна ломать event loop, если процесс способен корректно продолжать.

## 5. Streams/backpressure

Для больших payload использовать streams и уважать backpressure. Нельзя буферизовать бесконечный input/output в memory.

## 6. Timers

- periodic timer handler должен быть быстрым;
- не допускать overlap долгой periodic job без явной policy;
- timeout/cancellation — часть contract внешней операции;
- timer callback exception не должен теряться.

## 7. Mutable state

Long-running object живёт дольше одного request. Поэтому запрещено хранить request-specific данные в shared property без явной lifecycle cleanup.

Кэш обязан иметь bound/TTL/eviction strategy.

## 8. Memory leaks

Следить за:

- listeners, которые никогда не снимаются;
- closures, удерживающими большие graphs;
- unbounded arrays/maps;
- pending promises;
- stream buffers;
- per-request diagnostics retained globally.

## 9. HTTP security

- normalize/validate path;
- reject traversal/NUL;
- MIME type определять allowlist/map, а не доверять user input;
- `X-Content-Type-Options: nosniff`;
- security headers добавлять согласно приложению;
- не выдавать arbitrary local files;
- request body size ограничивать для endpoint, принимающих body;
- authentication/authorization не смешивать с transport convenience.

## 10. Embedded frontend

Boilerplate server раздаёт только bundled `frontend/dist`. SPA fallback разрешён только для extensionless route. Нельзя превращать static handler в произвольный filesystem browser.

`index.html` не кэшируется aggressively; content-hashed Vite assets MAY иметь `immutable` cache.

## 11. Shutdown

Server command SHOULD иметь graceful shutdown policy:

- перестать принимать новые соединения;
- закончить/отменить active work по deadline;
- закрыть sockets/resources;
- сохранить необходимое external state;
- завершиться non-zero при shutdown failure, если это materially важно.

## 12. Observability

- logs structured/consistent;
- request correlation id при необходимости;
- не логировать secrets/body целиком;
- event loop lag/queue/memory MAY измеряться у серьёзного daemon;
- human-readable log text проекта — на русском.

## 13. Testing

Pure request handler SHOULD быть тестируем без реального socket: подать PSR-7 request и проверить response. Network integration tests — отдельно.

## Источники

- ReactPHP: https://reactphp.org/
- ReactPHP HTTP: https://reactphp.org/http/
- ReactPHP EventLoop: https://reactphp.org/event-loop/
- ReactPHP Streams: https://reactphp.org/stream/
