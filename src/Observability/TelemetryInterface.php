<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Observability;

interface TelemetryInterface
{
    public function record(string $event, array $context = []): void;
}
