<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Tests;

use Mirabel\RabbitMQ\Outbox\OutboxDispatcher;
use Mirabel\RabbitMQ\Outbox\OutboxMessage;
use Mirabel\RabbitMQ\Outbox\OutboxPublisherInterface;
use Mirabel\RabbitMQ\Outbox\OutboxStoreInterface;
use PHPUnit\Framework\TestCase;

final class OutboxTest extends TestCase
{
    public function testSuccessfulPublicationMarksMessageAsPublished(): void
    {
        $message = new OutboxMessage('message-1', 'events', 'orders.created', '{"id":1}');
        $store = new OutboxStoreForTest([$message]);
        $publisher = new OutboxPublisherForTest();

        $published = (new OutboxDispatcher($store, $publisher))->dispatch();

        self::assertSame(1, $published);
        self::assertSame(['message-1'], $store->published);
        self::assertSame([], $store->failed);
        self::assertSame([$message], $publisher->messages);
    }

    public function testFailedPublicationRemainsAvailableForLaterRetry(): void
    {
        $message = new OutboxMessage('message-2', 'events', 'orders.created', '{"id":2}');
        $store = new OutboxStoreForTest([$message]);
        $publisher = new OutboxPublisherForTest(new \RuntimeException('broker unavailable'));

        $published = (new OutboxDispatcher($store, $publisher))->dispatch();

        self::assertSame(0, $published);
        self::assertSame([], $store->published);
        self::assertArrayHasKey('message-2', $store->failed);
    }

    public function testMessageSurvivesAJsonRoundTripWithHeaders(): void
    {
        $original = new OutboxMessage('message-3', 'events', 'orders.paid', '{"id":3}', [
            'delivery_mode' => 2,
            'application_headers' => new \PhpAmqpLib\Wire\AMQPTable([
                'x-idempotency-key' => 'order-3',
                'x-schema-version' => 2,
            ]),
        ]);

        $stored = json_decode(json_encode($original->toArray(), JSON_THROW_ON_ERROR), true);
        $restored = OutboxMessage::fromArray($stored);

        self::assertSame('orders.paid', $restored->routingKey);
        self::assertSame(2, $restored->properties['delivery_mode']);
        self::assertSame(
            ['x-idempotency-key' => 'order-3', 'x-schema-version' => 2],
            $restored->properties['application_headers']->getNativeData(),
        );
    }
}

final class OutboxStoreForTest implements OutboxStoreInterface
{
    /** @param list<OutboxMessage> $messages */
    public function __construct(private array $messages)
    {
    }

    public array $published = [];
    public array $failed = [];

    public function add(OutboxMessage $message): void
    {
        $this->messages[] = $message;
    }

    public function pending(int $limit): iterable
    {
        yield from array_slice($this->messages, 0, $limit);
    }

    public function markPublished(string $id): void
    {
        $this->published[] = $id;
    }

    public function markFailed(string $id, \Throwable $exception): void
    {
        $this->failed[$id] = $exception;
    }
}

final class OutboxPublisherForTest implements OutboxPublisherInterface
{
    public array $messages = [];

    public function __construct(private readonly ?\Throwable $failure = null)
    {
    }

    public function publish(OutboxMessage $message): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->messages[] = $message;
    }
}
