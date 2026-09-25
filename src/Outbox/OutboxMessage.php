<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Outbox;

use InvalidArgumentException;

final class OutboxMessage
{
    public function __construct(
        public readonly string $id,
        public readonly string $exchange,
        public readonly string $routingKey,
        public readonly string $body,
        public readonly array $properties = [],
    ) {
        if ($this->id === '' || $this->exchange === '' || $this->routingKey === '') {
            throw new InvalidArgumentException('Outbox message identity and destination are required.');
        }
    }
}
