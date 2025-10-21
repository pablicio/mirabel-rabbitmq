<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq;

use Pablicio\MirabelRabbitmq\Contracts\ConnectionManagerInterface;
use Pablicio\MirabelRabbitmq\Contracts\PublisherInterface;
use Pablicio\MirabelRabbitmq\Contracts\ConsumerInterface;

class RabbitMQManager
{
    public function __construct(
        private readonly ConnectionManagerInterface $connectionManager,
        private readonly PublisherInterface $publisher,
        private readonly ConsumerInterface $consumer
    ) {
    }

    /**
     * Get a publisher instance
     */
    public function publisher(): PublisherInterface
    {
        return clone $this->publisher;
    }

    /**
     * Get a consumer instance
     */
    public function consumer(): ConsumerInterface
    {
        return clone $this->consumer;
    }

    /**
     * Get the connection manager
     */
    public function connections(): ConnectionManagerInterface
    {
        return $this->connectionManager;
    }

    /**
     * Publish a message (shorthand)
     */
    public function publish(string $routingKey, mixed $payload, array $properties = []): bool
    {
        return $this->publisher()->publish($routingKey, $payload, $properties);
    }

    /**
     * Consume messages (shorthand)
     */
    public function consume(string $queue, \Closure $callback, array $options = []): void
    {
        $this->consumer()->consume($queue, $callback, $options);
    }
}
