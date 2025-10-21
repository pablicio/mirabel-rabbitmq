<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq\Contracts;

interface PublisherInterface
{
    /**
     * Publish a message to RabbitMQ
     *
     * @param string $routingKey The routing key for the message
     * @param mixed $payload The message payload
     * @param array<string, mixed> $properties Additional message properties
     * @return bool True if published successfully
     */
    public function publish(string $routingKey, mixed $payload, array $properties = []): bool;

    /**
     * Set the exchange to publish to
     *
     * @param string $exchange The exchange name
     * @return self
     */
    public function toExchange(string $exchange): self;

    /**
     * Set the connection to use
     *
     * @param string $connection The connection name
     * @return self
     */
    public function connection(string $connection): self;
}
