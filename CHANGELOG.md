# Changelog

## Unreleased

### Added — constant notation

- **Events and workers are declared with constants.** An event needs at most
  `const ROUTING_KEY` (and `const SCHEMA_VERSION`); a worker groups `QUEUE`,
  `EVENTS`, `ROUTING_KEYS`, `ATTEMPTS`, `RETRY_DELAY`, `PREFETCH`, `QUEUE_TYPE`
  and `EXCHANGE_TYPE` in one `const` statement.
- **Convention for what is left out.** An event without a routing key is named
  after its class (`PedidoPago` → `pedido.pago`, `OrderReceivedEvent` →
  `order.received`) instead of throwing `LogicException`; a worker without
  `QUEUE` gets its class name in kebab case (`EmitirNotaFiscal` →
  `emitir-nota-fiscal`).
- `EVENTS` subscribes by event class, so a routing key is written in one place.
- `Worker::definition()` and `Event::routingKey()` show what was resolved,
  without opening a connection.
- The previous notations (lowercase `routing_keys`/`options`/`retry_options`
  and the static properties) keep working; uppercase constants win.

### Added — Laravel bridge

- `MirabelServiceProvider`, registered by package discovery: the
  `MB_RABBITMQ_*` variables are kept in `config('mirabel')` and exported back
  to the environment at boot, so they survive `config:cache` (a variable set by
  the server always wins); the library logs to the Laravel log
  (`MB_RABBITMQ_LOG_CHANNEL`); `php artisan mirabel:work <Worker>` runs a
  worker built by the container. The core still does not depend on Laravel.
- `Mirabel::useLogger()` sets the default logger of every event and worker that
  does not override `logger()`.
- `MB_RABBITMQ_LOG_LEVEL` (default `info`) keeps the per-message debug lines out
  of the Laravel log; `MinimumLevelLogger` is the PSR-3 filter behind it.
- `php artisan mirabel:declare [Worker...]` declares the topology of the given
  workers, or of every worker in `app/Workers`.

### Added — operating queues from the worker

- `pending()`, `counts()` (queue, `.retry`, `.error`), `peek()` and `failed()`
  read a worker's queues without consuming; `Envelope::failure()` says why a
  message is in the error queue, `Envelope::toArray()` prints a message as
  Mirabel sees it, `Envelope::$routingKey`.
- `reprocess(?callable $which, ?int $limit)` moves picked messages from the
  error queue back as fresh deliveries (no retry history, confirmed before the
  original leaves); `discard(callable $which)` deletes picked ones; `purge()`
  empties the worker's queue.
- `reject(because: ...)` / `nack(because: ...)` write a reason on the message
  (`x-mirabel-detail`, `failure()['detail']`).
- Queue limits: `MAX_LENGTH` and `OVERFLOW` (`drop-head`, `reject-publish`).
- Mandatory events: `const MANDATORY = true` returns an unroutable message as
  `UnroutableException` (312 `NO_ROUTE`), with no retries.
- `PdoIdempotencyStore` and `PdoOutboxStore` (SQLite, MySQL, PostgreSQL).
- Laravel bridge: the outbox migration, `OutboxStoreInterface` bound to the
  application's PDO, and `php artisan mirabel:outbox` with backoff.
- `ConsoleLogger`, one line per log message, for workers run as scripts.

### Changed

- Transport and topology errors surface as `ConnectionException` ("Cannot
  reach RabbitMQ at host:port: reason") and `TopologyException` (with the
  queue name and the broker's reason) instead of the client library's
  exceptions.

### Added

- `Worker::declare()` declares the queues, exchanges and bindings without
  consuming. Until a queue exists, an event published with its routing key is
  dropped by the broker; declaring at deploy time closes that window.

### Fixed

- **Retries no longer fan out to other services.** The retry queue used to
  dead-letter back through the main exchange with the event's routing key, so a
  message retried by one worker was delivered again to every other queue bound
  to that key. It now dead-letters through the default exchange straight to the
  worker's own queue.
- **Publisher confirms detect rejections.** A `basic.nack` from the broker was
  silently ignored. `publish()` now throws `PublishNotConfirmedException`, and
  waiting for the confirm is bounded by `MB_RABBITMQ_READ_WRITE_TIMEOUT`.
- **`publish()` cannot hang forever.** With the default
  `MB_RABBITMQ_RECONNECT_ATTEMPTS=0` (unlimited), a publish inside an HTTP
  request would retry forever while the broker was down. Publishing now has its
  own bounded budget, `MB_RABBITMQ_PUBLISH_RETRIES` (default `3`).
- **`Envelope::ack()` after an exception no longer kills the channel.** The
  envelope acked through the channel, bypassing `AMQPMessage`, so a handler that
  acked and then threw caused a second answer for the same delivery tag
  (`PRECONDITION_FAILED - unknown delivery tag`) and a reconnect.
- **`Envelope::nack()` respects `max_attempts`.** A manual nack on the last
  attempt used to go back to the retry queue forever.
- A failure while closing a broken channel or connection no longer replaces the
  original exception.
- `Worker::health()['connected']` reports the real connection state instead of
  "not shutting down".
- The reconnect budget of a worker resets after a successful connection, so
  `MB_RABBITMQ_RECONNECT_ATTEMPTS` limits consecutive failures only.
- A worker whose topology the broker refuses (`PRECONDITION_FAILED`, e.g. a
  queue that already exists with another type, or `ACCESS_REFUSED`) now stops
  with the error instead of reconnecting forever — silently, with the default
  `NullLogger`.

### Added

- `handle(Envelope)` acks automatically when the handler returns without
  answering the broker.
- `reject()` (on the envelope or the worker) parks the message in the error
  queue immediately, without spending retries. `nack()` stays the "try again
  later" answer.
- Poison messages — bodies that cannot be decoded — go straight to the error
  queue without reaching the handler.
- Parked messages carry `x-mirabel-failure-reason`, `x-mirabel-attempts`,
  `x-mirabel-failed-at` and `x-mirabel-exception` headers.
- `Envelope::$attempt`, `Envelope::isRedelivered()`, `Envelope::response()`.
- `OutboxMessage::toArray()` / `fromArray()`, so an outbox store can keep the
  message (headers included) as JSON.
- `Worker::stop()`; the consume loop wakes up every second to honour it.
- `serializer()` hook on events and workers.
- An empty retry queue declared with different arguments (older version, new
  `delay`) is recreated automatically; one that still holds messages is kept and
  reported.
- Quorum queues: `public static array $options = ['queue_type' => 'quorum']`
  declares the queue, its `.retry` and its `.error` as replicated quorum
  queues.
- Integration tests that run the real `Worker` against RabbitMQ,
  `docker-compose.yml`, GitHub Actions CI (PHP 8.2–8.4), `composer test` and
  `composer test:integration`.

### Changed

- A clear `LogicException` when an event has no `$routingKey` or a worker has
  no `$queue`.
- `composer.json` type is now `library`.
- `LoggerTelemetry` logs at `debug` instead of `info`: one line per message is
  telemetry, not an operational event.

### Upgrading

- Nothing to change in your code.
- The first time an upgraded worker starts, it recreates `<queue>.retry` if the
  queue is empty. If it still holds messages from the old version, let them
  drain with the old worker (or move them) and restart.
- If you relied on `reject()` without requeue sending the message to retry,
  use `nack()` instead.
