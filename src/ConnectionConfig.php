<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ;

use InvalidArgumentException;

final class ConnectionConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $user,
        public readonly string $password,
        public readonly string $vhost = '/',
        public readonly string $exchange = 'my-exchange',
        public readonly string $exchangeType = 'topic',
        public readonly bool $publisherConfirms = false,
        public readonly float $connectTimeout = 3.0,
        public readonly float $readWriteTimeout = 3.0,
        public readonly int $heartbeat = 30,
        public readonly int $reconnectAttempts = 0,
        public readonly int $reconnectDelayMs = 1000,
        public readonly int $reconnectMaxDelayMs = 30000,
        public readonly int $publishRetries = 3,
    ) {
        if ($this->port < 1 || $this->port > 65535) {
            throw new InvalidArgumentException('RabbitMQ port must be between 1 and 65535.');
        }

        if ($this->exchange === '') {
            throw new InvalidArgumentException('RabbitMQ exchange cannot be empty.');
        }

        if ($this->connectTimeout <= 0 || $this->readWriteTimeout <= 0) {
            throw new InvalidArgumentException('RabbitMQ timeouts must be greater than zero.');
        }

        if ($this->heartbeat < 0 || $this->reconnectAttempts < 0
            || $this->reconnectDelayMs < 0 || $this->reconnectMaxDelayMs < $this->reconnectDelayMs
            || $this->publishRetries < 0
        ) {
            throw new InvalidArgumentException('RabbitMQ heartbeat and reconnect values are invalid.');
        }
    }

    public static function fromEnvironment(): self
    {
        return new self(
            host: self::string('MB_RABBITMQ_HOST', 'localhost'),
            port: self::integer('MB_RABBITMQ_PORT', 5672),
            user: self::string('MB_RABBITMQ_USER', 'guest'),
            password: self::string('MB_RABBITMQ_PASSWORD', 'guest'),
            vhost: self::string('MB_RABBITMQ_VHOST', '/'),
            exchange: self::string('MB_RABBITMQ_EXCHANGE', 'my-exchange'),
            exchangeType: self::string('MB_RABBITMQ_EXCHANGE_TYPE', 'topic'),
            publisherConfirms: self::boolean('MB_RABBITMQ_PUBLISHER_CONFIRMS', false),
            connectTimeout: self::float('MB_RABBITMQ_CONNECT_TIMEOUT', 3.0),
            readWriteTimeout: self::float('MB_RABBITMQ_READ_WRITE_TIMEOUT', 3.0),
            heartbeat: self::integer('MB_RABBITMQ_HEARTBEAT', 30),
            reconnectAttempts: self::integer('MB_RABBITMQ_RECONNECT_ATTEMPTS', 0),
            reconnectDelayMs: self::integer('MB_RABBITMQ_RECONNECT_DELAY_MS', 1000),
            reconnectMaxDelayMs: self::integer('MB_RABBITMQ_RECONNECT_MAX_DELAY_MS', 30000),
            publishRetries: self::integer('MB_RABBITMQ_PUBLISH_RETRIES', 3),
        );
    }

    /**
     * Exponential backoff for the given attempt (1-based), capped by reconnectMaxDelayMs.
     */
    public function backoffDelayMs(int $attempt): int
    {
        return min(
            $this->reconnectMaxDelayMs,
            $this->reconnectDelayMs * (2 ** min(max($attempt, 1) - 1, 10)),
        );
    }

    private static function string(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }

    private static function integer(string $name, int $default): int
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : (int) $value;
    }

    private static function float(string $name, float $default): float
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : (float) $value;
    }

    private static function boolean(string $name, bool $default): bool
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    }
}