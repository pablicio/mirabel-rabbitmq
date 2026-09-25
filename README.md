
# Mirabel RabbitMQ

Framework-agnostic RabbitMQ primitives for PHP 8.2+, built on
`php-amqplib/php-amqplib`.

Documentação completa: [docs/README.md](docs/README.md)

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
MB_RABBITMQ_PUBLISHER_CONFIRMS=false
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

## Consume

```php
<?php

use Mirabel\RabbitMQ\Worker;

final class OrderWorker extends Worker
{
    const QUEUE = 'orders.worker',
        routing_keys = ['orders.received'],
        options = ['exchange_type' => 'topic'],
        retry_options = [
            'x-message-ttl' => 1000,
            'max-attempts' => 8,
        ];

    public function work($msg)
    {
        try {
            process($msg->body);

            return $this->ack($msg);
        } catch (\Throwable $exception) {
            return $this->nack($msg);
        }
    }
}

(new OrderWorker())->subscribe();
```

Failed messages are sent through a TTL retry queue and returned to the normal
queue through dead-lettering. Attempts are read from RabbitMQ's `x-death`
header, so the count survives worker restarts. Once `max_attempts` is reached,
the message is published to the worker's `.error` queue.

Delivery is at-least-once. Handlers should be idempotent, and an explicit
`ack()` is required after successful processing.
