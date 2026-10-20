<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Exception;

use RuntimeException;

/**
 * A mandatory event reached the exchange and matched no queue, so the broker
 * returned it (312 NO_ROUTE) instead of dropping it. Publishing again does
 * not help until some queue is bound to the routing key.
 */
final class UnroutableException extends RuntimeException
{
    public static function returned(int $code, string $reason, string $exchange, string $routingKey): self
    {
        return new self(sprintf(
            'RabbitMQ returned the message: %d %s (exchange "%s", routing key "%s").',
            $code,
            $reason,
            $exchange,
            $routingKey,
        ), $code);
    }
}
