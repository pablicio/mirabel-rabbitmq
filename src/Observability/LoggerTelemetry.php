<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Observability;

use Psr\Log\LoggerInterface;

final class LoggerTelemetry implements TelemetryInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function record(string $event, array $context = []): void
    {
        $this->logger->info('RabbitMQ telemetry', ['event' => $event] + $context);
    }
}
