<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Observability;

final class MetricsCollector implements TelemetryInterface
{
    /** @var array<string, int> */
    private array $counts = [];

    public function record(string $event, array $context = []): void
    {
        $this->counts[$event] = ($this->counts[$event] ?? 0) + 1;
    }

    public function count(string $event): int
    {
        return $this->counts[$event] ?? 0;
    }

    /** @return array<string, int> */
    public function snapshot(): array
    {
        return $this->counts;
    }

    /** @return array<string, int> */
    public function export(): array
    {
        return $this->snapshot();
    }

    public function toPrometheus(): string
    {
        $lines = [
            '# HELP rabbitmq_messages_total Total number of RabbitMQ telemetry events.',
            '# TYPE rabbitmq_messages_total counter',
        ];

        foreach ($this->snapshot() as $event => $count) {
            $lines[] = sprintf('rabbitmq_messages_total{event="%s"} %d', $event, $count);
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
