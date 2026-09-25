<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Observability;

final class CompositeTelemetry implements TelemetryInterface
{
    /** @var list<TelemetryInterface> */
    private readonly array $telemetries;

    public function __construct(TelemetryInterface ...$telemetries)
    {
        $this->telemetries = $telemetries;
    }

    public function record(string $event, array $context = []): void
    {
        foreach ($this->telemetries as $telemetry) {
            $telemetry->record($event, $context);
        }
    }
}
