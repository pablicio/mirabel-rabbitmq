<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq\Contracts;

use Closure;

interface ConsumerInterface
{
    /**
     * Start consuming messages from a queue
     *
     * @param string $queue The queue name
     * @param Closure $callback The message handler
     * @param array<string, mixed> $options Consumer options
     * @return void
     */
    public function consume(string $queue, Closure $callback, array $options = []): void;

    /**
     * Bind queue to routing keys
     *
     * @param array<string> $routingKeys The routing keys to bind
     * @return self
     */
    public function bindKeys(array $routingKeys): self;

    /**
     * Set the connection to use
     *
     * @param string $connection The connection name
     * @return self
     */
    public function connection(string $connection): self;

    /**
     * Enable retry mechanism
     *
     * @param int $maxAttempts Maximum retry attempts
     * @param int $initialDelay Initial delay in milliseconds
     * @return self
     */
    public function withRetry(int $maxAttempts = 3, int $initialDelay = 1000): self;

    /**
     * Stop consuming messages
     *
     * @return void
     */
    public function stop(): void;
}
