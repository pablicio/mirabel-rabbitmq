# Mirabel RabbitMQ

Framework-agnostic RabbitMQ primitives for PHP 8.2+, built on
[`php-amqplib/php-amqplib`](https://github.com/php-amqplib/php-amqplib) — the
mature, battle-tested AMQP client created by Vadim Zaliva and Alvaro Videla and
kept alive by its community; it is the client the official RabbitMQ PHP
tutorials use. Mirabel does not replace it: it stands on it and packages the
decisions every service ends up repeating (topology, retries, error queues,
envelopes) behind one class per event, one class per worker and one verb to use
each: `publish()` and `subscribe()`.

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
    const ROUTING_KEY = 'orders.received';
}

(new OrderReceived(['id' => 123]))->publish();
```

The constant is optional: without it the routing key comes from the class name
(`OrderReceived` → `order.received`, `PedidoPagoEvent` → `pedido.pago`).
`OrderReceived::routingKey()` shows what was resolved.

With `MB_RABBITMQ_PUBLISHER_CONFIRMS=true`, `publish()` returns only after the
broker has accepted the message, and throws `PublishNotConfirmedException` when
it refuses it. Transport failures are retried a bounded number of times
(`MB_RABBITMQ_PUBLISH_RETRIES`) with the same `message_id`.

## Consume

```php
<?php

use Mirabel\RabbitMQ\Envelope;
use Mirabel\RabbitMQ\Worker;

final class ChargeOrder extends Worker
{
    const QUEUE = 'billing.orders-received',
        EVENTS = [OrderReceived::class],
        ATTEMPTS = 4,
        RETRY_DELAY = 5_000;

    public function handle(Envelope $envelope): void
    {
        charge($envelope->body['id']); // return normally → ack; throw → retry
    }
}

(new ChargeOrder())->subscribe();
```

Every constant is optional. What is left out follows a convention:

| Constant | Meaning | When omitted |
| --- | --- | --- |
| `QUEUE` | queue name | class name in kebab case, without `Worker` (`ChargeOrder` → `charge-order`) |
| `EVENTS` | event classes to subscribe to | — |
| `ROUTING_KEYS` | extra routing keys (`*`/`#` allowed) | — |
| `ATTEMPTS` | deliveries before the error queue | `1` |
| `RETRY_DELAY` | milliseconds in the retry queue | `0` |
| `PREFETCH` | unacked messages per consumer | broker default |
| `QUEUE_TYPE` | `classic` or `quorum` | `classic` |
| `EXCHANGE_TYPE` | exchange type | `MB_RABBITMQ_EXCHANGE_TYPE` |

`EVENTS` and `ROUTING_KEYS` add up. `ChargeOrder::definition()` returns the
queue, keys, retry and options the worker will declare.

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
`retry_options`, and the static properties (`$routingKey`, `$queue`,
`$routingKeys`, `$retry`, `$options`), are still supported. When both are
present, the uppercase constant wins.

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
