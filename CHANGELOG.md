# Changelog

## Unreleased

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
