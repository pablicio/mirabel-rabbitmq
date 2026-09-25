<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Tests;

use Mirabel\RabbitMQ\Observability\CompositeTelemetry;
use Mirabel\RabbitMQ\Observability\LoggerTelemetry;
use Mirabel\RabbitMQ\Observability\MetricsCollector;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class TelemetryRuntimeTest extends TestCase
{
    public function testLoggerTelemetryWritesStructuredEvents(): void
    {
        $logger = new TestLogger();
        $telemetry = new LoggerTelemetry($logger);

        $telemetry->record('processed', ['queue' => 'orders', 'message_id' => 'm-1']);

        self::assertCount(1, $logger->entries);
        self::assertSame('RabbitMQ telemetry', $logger->entries[0]['message']);
        self::assertSame('processed', $logger->entries[0]['context']['event']);
        self::assertSame('orders', $logger->entries[0]['context']['queue']);
    }

    public function testCompositeTelemetryPublishesToAllSinks(): void
    {
        $logger = new TestLogger();
        $metrics = new MetricsCollector();
        $telemetry = new CompositeTelemetry($metrics, new LoggerTelemetry($logger));

        $telemetry->record('published', ['routing_key' => 'orders.created']);
        $telemetry->record('published', ['routing_key' => 'orders.created']);

        self::assertSame(2, $metrics->count('published'));
        self::assertCount(2, $logger->entries);
    }
}

final class TestLogger extends AbstractLogger
{
    public array $entries = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->entries[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
