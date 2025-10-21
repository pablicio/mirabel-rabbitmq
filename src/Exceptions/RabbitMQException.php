<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq\Exceptions;

use Exception;

class RabbitMQException extends Exception
{
    public static function connectionFailed(string $connection, string $reason): self
    {
        return new self("Failed to connect to RabbitMQ [{$connection}]: {$reason}");
    }

    public static function publishFailed(string $routingKey, string $reason): self
    {
        return new self("Failed to publish message to [{$routingKey}]: {$reason}");
    }

    public static function consumeFailed(string $queue, string $reason): self
    {
        return new self("Failed to consume from queue [{$queue}]: {$reason}");
    }

    public static function invalidConfiguration(string $key): self
    {
        return new self("Invalid RabbitMQ configuration for key [{$key}]");
    }

    public static function serializationFailed(string $reason): self
    {
        return new self("Serialization failed: {$reason}");
    }

    public static function deserializationFailed(string $reason): self
    {
        return new self("Deserialization failed: {$reason}");
    }
}
