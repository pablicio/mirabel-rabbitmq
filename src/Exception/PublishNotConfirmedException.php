<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Exception;

use RuntimeException;

/**
 * The broker answered a publisher confirm with basic.nack: the message was not
 * accepted and must be published again (or reported) by the caller.
 */
final class PublishNotConfirmedException extends RuntimeException
{
    public static function forRoutingKey(string $exchange, string $routingKey): self
    {
        return new self(sprintf(
            'RabbitMQ did not confirm the message published to exchange "%s" with routing key "%s".',
            $exchange,
            $routingKey,
        ));
    }
}
