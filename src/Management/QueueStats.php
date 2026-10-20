<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Management;

/**
 * The four questions that matter for a queue, plus its pace, as the broker's
 * management API reports them. Rates are messages per second, averaged by the
 * broker over its last samples (every 5 seconds by default).
 */
final class QueueStats
{
    public function __construct(
        public readonly string $queue,
        public readonly int $ready,
        public readonly int $unacked,
        public readonly int $consumers,
        public readonly ?int $oldestWaitSeconds,
        public readonly float $publishRate,
        public readonly float $ackRate,
    ) {
    }

    /** @param array<string, mixed> $queue a /api/queues/{vhost}/{name} answer */
    public static function fromApi(array $queue, int $now): self
    {
        $head = $queue['head_message_timestamp'] ?? null;

        return new self(
            (string) ($queue['name'] ?? ''),
            (int) ($queue['messages_ready'] ?? 0),
            (int) ($queue['messages_unacknowledged'] ?? 0),
            (int) ($queue['consumers'] ?? 0),
            is_numeric($head) ? max(0, $now - (int) $head) : null,
            (float) ($queue['message_stats']['publish_details']['rate'] ?? 0.0),
            (float) ($queue['message_stats']['ack_details']['rate'] ?? 0.0),
        );
    }

    /**
     * Seconds until the queue is empty at the current pace; null when it is
     * not shrinking.
     */
    public function secondsToDrain(): ?int
    {
        $net = $this->ackRate - $this->publishRate;

        return $this->ready === 0 ? 0 : ($net > 0 ? (int) ceil($this->ready / $net) : null);
    }
}
