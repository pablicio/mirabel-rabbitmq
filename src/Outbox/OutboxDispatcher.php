<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Outbox;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class OutboxDispatcher
{
    public function __construct(
        private readonly OutboxStoreInterface $store,
        private readonly OutboxPublisherInterface $publisher,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function dispatch(int $limit = 100): int
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Outbox dispatch limit must be greater than zero.');
        }

        $published = 0;
        foreach ($this->store->pending($limit) as $message) {
            try {
                $this->publisher->publish($message);
                $this->store->markPublished($message->id);
                $published++;
            } catch (\Throwable $exception) {
                $this->store->markFailed($message->id, $exception);
                $this->logger->error('Outbox message publication failed.', [
                    'message_id' => $message->id,
                    'exchange' => $message->exchange,
                    'routing_key' => $message->routingKey,
                    'exception' => $exception,
                ]);
            }
        }

        return $published;
    }
}
