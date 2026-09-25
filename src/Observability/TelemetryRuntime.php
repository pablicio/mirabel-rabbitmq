<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Observability;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class TelemetryRuntime
{
    private static ?MetricsCollector $metrics = null;
    private static ?TelemetryInterface $telemetry = null;

    public static function record(string $event, array $context = [], ?LoggerInterface $logger = null): void
    {
        self::telemetry($logger)->record($event, $context);
    }

    public static function metrics(): MetricsCollector
    {
        self::telemetry();

        return self::$metrics;
    }

    public static function reset(): void
    {
        self::$metrics = null;
        self::$telemetry = null;
    }

    private static function telemetry(?LoggerInterface $logger = null): TelemetryInterface
    {
        if (self::$telemetry === null) {
            self::$metrics = new MetricsCollector();
            self::$telemetry = new CompositeTelemetry(
                self::$metrics,
                new LoggerTelemetry($logger ?? new NullLogger()),
            );
        }

        return self::$telemetry;
    }
}