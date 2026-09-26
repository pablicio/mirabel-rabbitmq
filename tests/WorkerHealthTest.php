<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Tests;

use Mirabel\RabbitMQ\Worker;
use PHPUnit\Framework\TestCase;

final class WorkerHealthTest extends TestCase
{
    public function testWorkerThatNeverSubscribedIsNotConnected(): void
    {
        $worker = new HealthAwareWorker();

        self::assertSame([
            'queue' => 'orders.received',
            'active_queue' => null,
            'connected' => false,
            'shutdown_requested' => false,
        ], $worker->health());
    }

    public function testStopIsReportedAsShutdownRequest(): void
    {
        $worker = new HealthAwareWorker();
        $worker->stop();

        self::assertTrue($worker->health()['shutdown_requested']);
    }
}

final class HealthAwareWorker extends Worker
{
    const QUEUE = 'orders.received';

    public function work($msg): void
    {
    }
}
