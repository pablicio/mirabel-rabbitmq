<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Tests;

use Mirabel\RabbitMQ\Observability\MetricsCollector;
use PHPUnit\Framework\TestCase;

final class ObservabilityTest extends TestCase
{
    public function testMetricsCollectorAccumulatesCountersAndSnapshots(): void
    {
        $metrics = new MetricsCollector();

        $metrics->record('published', ['routing_key' => 'orders.received']);
        $metrics->record('publish_retry', ['attempt' => 1]);
        $metrics->record('message_retried', ['attempt' => 2]);
        $metrics->record('published', ['routing_key' => 'orders.received']);

        self::assertSame(2, $metrics->count('published'));
        self::assertSame(1, $metrics->count('publish_retry'));
        self::assertSame(1, $metrics->count('message_retried'));
        self::assertSame([
            'published' => 2,
            'publish_retry' => 1,
            'message_retried' => 1,
        ], $metrics->snapshot());
    }

    public function testMetricsCollectorExportsMetricsForRuntimeMonitoring(): void
    {
        $metrics = new MetricsCollector();
        $metrics->record('published', ['routing_key' => 'orders.created']);
        $metrics->record('published', ['routing_key' => 'orders.created']);

        self::assertSame([
            'published' => 2,
        ], $metrics->export());
    }

    public function testMetricsCollectorRendersPrometheusMetrics(): void
    {
        $metrics = new MetricsCollector();
        $metrics->record('published', ['routing_key' => 'orders.created']);
        $metrics->record('published', ['routing_key' => 'orders.created']);
        $metrics->record('message_retried', ['attempt' => 2]);

        $prometheus = $metrics->toPrometheus();

        self::assertStringContainsString('# HELP rabbitmq_messages_total Total number of RabbitMQ telemetry events.', $prometheus);
        self::assertStringContainsString('rabbitmq_messages_total{event="published"} 2', $prometheus);
        self::assertStringContainsString('rabbitmq_messages_total{event="message_retried"} 1', $prometheus);
    }
}
