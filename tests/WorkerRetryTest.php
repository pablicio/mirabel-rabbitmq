<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Tests;

use Mirabel\RabbitMQ\ConnectionConfig;
use Mirabel\RabbitMQ\Idempotency\IdempotencyStoreInterface;
use Mirabel\RabbitMQ\Worker;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class WorkerRetryTest extends TestCase
{
    public function testClassicNackSendsLastAttemptToErrorExchange(): void
    {
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $channel->expects(self::once())
            ->method('basic_publish')
            ->with(self::isInstanceOf(AMQPMessage::class), 'orders.error', 'orders');
        $channel->expects(self::once())
            ->method('basic_ack')
            ->with(7, false);

        $message = new AMQPMessage('payload', [
            'application_headers' => new AMQPTable([
                'x-death' => [
                    [
                        'queue' => 'orders.retry',
                        'count' => 1,
                    ],
                ],
            ]),
        ]);
        $message->setChannel($channel);
        $message->setDeliveryTag(7);

        $config = new ConnectionConfig('localhost', 5672, 'guest', 'guest');
        $worker = new ClassicRetryWorkerForTest();
        $process = new ReflectionMethod(Worker::class, 'process');
        $process->setAccessible(true);

        $process->invoke(
            $worker,
            $channel,
            $config,
            'orders.error',
            $message,
            ['max_attempts' => 2],
            'orders',
        );
    }

    public function testDuplicateMessageIsAcknowledgedWithoutCallingTheHandler(): void
    {
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $channel->expects(self::once())->method('basic_ack')->with(8);

        $message = new AMQPMessage('payload', ['message_id' => 'event-8']);
        $message->setChannel($channel);
        $message->setDeliveryTag(8);

        $worker = new DuplicateWorkerForTest(new InMemoryIdempotencyStore(['event-8']));
        $process = new ReflectionMethod(Worker::class, 'process');
        $process->setAccessible(true);

        $process->invoke(
            $worker,
            $channel,
            new ConnectionConfig('localhost', 5672, 'guest', 'guest'),
            'orders.error',
            $message,
            ['max_attempts' => 2],
            'orders',
        );
    }
}

final class ClassicRetryWorkerForTest extends Worker
{
    const QUEUE = 'orders';

    public function work($message)
    {
        return $this->nack($message);
    }
}

final class DuplicateWorkerForTest extends Worker
{
    public function __construct(private readonly IdempotencyStoreInterface $store)
    {
    }

    protected function idempotencyStore(): ?IdempotencyStoreInterface
    {
        return $this->store;
    }

    public function work($message)
    {
        throw new \LogicException('Duplicate message reached handler.');
    }
}

final class InMemoryIdempotencyStore implements IdempotencyStoreInterface
{
    /** @param list<string> $keys */
    public function __construct(private array $keys)
    {
    }

    public function has(string $key): bool
    {
        return in_array($key, $this->keys, true);
    }

    public function remember(string $key): void
    {
        $this->keys[] = $key;
    }
}
