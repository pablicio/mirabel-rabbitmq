<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Tests;

use Mirabel\RabbitMQ\Worker;
use PHPUnit\Framework\TestCase;

final class WorkerHealthTest extends TestCase
{
    public function testHealthCheckReportsQueueAndConnectionState(): void
    {
        $worker = new HealthAwareWorker();

        self::assertSame([
            'queue' => 'orders.received',
            'active_queue' => null,
            'connected' => true,
            'shutdown_requested' => false,
        ], $worker->health());
    }
}

final class HealthAwareWorker extends Worker
{
    const QUEUE = 'orders.received';

    public function work($msg): void
    {
    }
}
