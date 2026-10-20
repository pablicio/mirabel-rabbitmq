<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Tests;

use Mirabel\RabbitMQ\Management\QueueStats;
use PHPUnit\Framework\TestCase;

final class QueueStatsTest extends TestCase
{
    public function testReadsTheManagementAnswerAndTheTimeToDrain(): void
    {
        $stats = QueueStats::fromApi([
            'name' => 'avisos.pedidos',
            'messages_ready' => 200,
            'messages_unacknowledged' => 1,
            'consumers' => 1,
            'head_message_timestamp' => 1_000,
            'message_stats' => [
                'publish_details' => ['rate' => 10.0],
                'ack_details' => ['rate' => 20.0],
            ],
        ], 1_015);

        self::assertSame(15, $stats->oldestWaitSeconds);
        self::assertSame(20, $stats->secondsToDrain());
    }

    public function testAQueueThatDoesNotShrinkNeverDrains(): void
    {
        $stats = QueueStats::fromApi(['name' => 'q', 'messages_ready' => 5], 0);

        self::assertNull($stats->oldestWaitSeconds);
        self::assertNull($stats->secondsToDrain());
    }

    public function testAnEmptyQueueHasNobodyWaiting(): void
    {
        $stats = QueueStats::fromApi([
            'name' => 'avisos.pedidos',
            'messages_ready' => 0,
            'head_message_timestamp' => 1_000,
        ], 1_046);

        self::assertNull($stats->oldestWaitSeconds);
        self::assertSame(0, $stats->secondsToDrain());
    }
}
