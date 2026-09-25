<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Outbox;

interface OutboxPublisherInterface
{
    public function publish(OutboxMessage $message): void;
}
