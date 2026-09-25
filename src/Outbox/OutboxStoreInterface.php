<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Outbox;

interface OutboxStoreInterface
{
    public function add(OutboxMessage $message): void;

    /** @return iterable<OutboxMessage> */
    public function pending(int $limit): iterable;

    public function markPublished(string $id): void;

    public function markFailed(string $id, \Throwable $exception): void;
}
