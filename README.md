# Mirabel RabbitMQ

Framework-agnostic RabbitMQ primitives for PHP 8.2+, built on
`php-amqplib/php-amqplib`. One class per event, one class per worker, one verb
to use each: `publish()` and `subscribe()`.

Documentação completa (pt-BR): [docs/README.md](docs/README.md) ·
Changes: [CHANGELOG.md](CHANGELOG.md)

## Install

```bash
composer require mirabel/rabbitmq
```

The core reads its connection lazily from the process environment:

```env
MB_RABBITMQ_HOST=localhost
MB_RABBITMQ_PORT=5672
MB_RABBITMQ_USER=guest
MB_RABBITMQ_PASSWORD=guest
MB_RABBITMQ_VHOST=/
MB_RABBITMQ_EXCHANGE=my-exchange
MB_RABBITMQ_EXCHANGE_TYPE=topic
MB_RABBITMQ_PUBLISHER_CONFIRMS=true
MB_RABBITMQ_PUBLISH_RETRIES=3
```

## Publish

```php
<?php

use Mirabel\RabbitMQ\Event;

final class OrderReceived extends Event
{
    public static string $routingKey = 'orders.received';
}

(new OrderReceived(['id' => 123]))->publish();
```

With `MB_RABBITMQ_PUBLISHER_CONFIRMS=true`, `publish()` returns only after the
broker has accepted the message, and throws `PublishNotConfirmedException` when
it refuses it. Transport failures are retried a bounded number of times
(`MB_RABBITMQ_PUBLISH_RETRIES`) with the same `message_id`.

## Consume

```php
<?php

use Mirabel\RabbitMQ\Envelope;
use Mirabel\RabbitMQ\Worker;

final class OrderWorker extends Worker
{
    public static string $queue = 'billing.orders-received';
    public static array $routingKeys = ['orders.received'];
    public static array $retry = ['delay' => 5000, 'max_attempts' => 4];

    public function handle(Envelope $envelope): void
    {
        charge($envelope->body['id']); // return normally → ack; throw → retry
    }
}

(new OrderWorker())->subscribe();
```

| The handler... | The worker... |
| --- | --- |
| returns | acks the message |
| throws, or calls `$envelope->nack()` | sends it to `<queue>.retry`; on the last attempt, to `<queue>.error` |
| calls `$envelope->reject()` | parks it in `<queue>.error` right away |
| receives a body that is not JSON | parks it in `<queue>.error` without calling the handler |

Attempts are read from RabbitMQ's `x-death` header, so the count survives
worker restarts. A retried message returns only to the queue that failed, never
to other queues bound to the same routing key. Parked messages carry
`x-mirabel-failure-reason`, `x-mirabel-attempts` and `x-mirabel-exception`
headers.

The classic `work($msg)` API with `const QUEUE`, `routing_keys`, `options` and
`retry_options` is still supported.

Delivery is at-least-once: handlers should be idempotent. A worker can plug in
an `IdempotencyStoreInterface` to skip messages it has already processed.

`$worker->stop()` (or SIGTERM/SIGINT with `ext-pcntl`) finishes the current
message and leaves `subscribe()`.

## Test

```bash
composer test                 # unit tests, no broker needed
docker compose up -d
composer test:integration     # unit + integration against a real RabbitMQ
```

## Disclaimer

Mirabel is a small library built for learning and for small services. Read the
limitations section of the docs before putting it in front of money.
