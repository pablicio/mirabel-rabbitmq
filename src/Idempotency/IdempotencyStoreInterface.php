<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Idempotency;

interface IdempotencyStoreInterface
{
    public function has(string $key): bool;

    public function remember(string $key): void;
}