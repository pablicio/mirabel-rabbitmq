<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Tests;

use Mirabel\RabbitMQ\Connection\ConnectionFactoryInterface;
use Mirabel\RabbitMQ\ConnectionConfig;
use Mirabel\RabbitMQ\Event;
use Mirabel\RabbitMQ\Exception\PublishNotConfirmedException;
use Mirabel\RabbitMQ\Publishing\PublisherRuntime;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    protected function tearDown(): void
    {
        PublisherRuntime::closeAll();
        foreach ([
            'EXCHANGE', 'EXCHANGE_TYPE', 'REUSE_CONNECTION', 'PUBLISHER_CONFIRMS', 'PUBLISH_RETRIES',
            'RECONNECT_ATTEMPTS', 'RECONNECT_DELAY_MS', 'RECONNECT_MAX_DELAY_MS',
        ] as $name) {
            putenv('MB_RABBITMQ_' . $name);
        }
    }

    public function testPublishesJsonThroughTheInjectedConnection(): void
    {
        putenv('MB_RABBITMQ_EXCHANGE=events');
        putenv('MB_RABBITMQ_EXCHANGE_TYPE=topic');

        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $channel->expects(self::once())
            ->method('exchange_declare')
            ->with('events', 'topic', false, true, false);
        $channel->expects(self::once())
            ->method('basic_publish')
            ->with(self::callback(function (AMQPMessage $message): bool {
                $headers = $message->get('application_headers')->getNativeData();

                return $message->getBody() === '{"id":42}'
                    && $message->get('message_id') === 'event-42'
                    && $message->get('correlation_id') === 'request-1'
                    && ($headers['x-schema-version'] ?? null) === 1
                    && ($headers['x-idempotency-key'] ?? null) === 'order-42';
            }), 'events', 'orders.received');
        $channel->expects(self::once())->method('close');

        $connection = $this->getMockBuilder(AbstractConnection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $connection->expects(self::once())->method('channel')->willReturn($channel);
        $connection->expects(self::once())->method('close');

        $event = new TestEvent(
            new StubConnectionFactory($connection),
            ['id' => 42],
        );

        $event->publish(
            messageId: 'event-42',
            correlationId: 'request-1',
            idempotencyKey: 'order-42',
        );
    }

    public function testRetriesAfterConnectionFailure(): void
    {
        putenv('MB_RABBITMQ_EXCHANGE=events');
        putenv('MB_RABBITMQ_RECONNECT_ATTEMPTS=1');
        putenv('MB_RABBITMQ_RECONNECT_DELAY_MS=0');
        putenv('MB_RABBITMQ_RECONNECT_MAX_DELAY_MS=0');

        $firstConnection = $this->getMockBuilder(AbstractConnection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $firstConnection->method('channel')->willThrowException(new \RuntimeException('connection lost'));
        $firstConnection->expects(self::once())->method('close');

        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $channel->expects(self::once())->method('exchange_declare');
        $channel->expects(self::once())->method('basic_publish');
        $channel->expects(self::once())->method('close');

        $secondConnection = $this->getMockBuilder(AbstractConnection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $secondConnection->expects(self::once())->method('channel')->willReturn($channel);
        $secondConnection->expects(self::once())->method('close');

        $event = new TestEvent(
            new SequenceConnectionFactory([$firstConnection, $secondConnection]),
            ['id' => 42],
        );

        $event->publish();
    }

    public function testCreatesOutboxMessageWithStableIdentity(): void
    {
        putenv('MB_RABBITMQ_EXCHANGE=events');

        $event = new TestEvent(
            new StubConnectionFactory($this->createMock(AbstractConnection::class)),
            ['id' => 42],
        );

        $message = $event->toOutboxMessage(
            messageId: 'event-42',
            correlationId: 'request-1',
            idempotencyKey: 'order-42',
            schemaVersion: 2,
        );

        self::assertSame('event-42', $message->id);
        self::assertSame('events', $message->exchange);
        self::assertSame('orders.received', $message->routingKey);
        self::assertSame('{"id":42}', $message->body);
        self::assertSame('request-1', $message->properties['correlation_id']);
        self::assertSame(
            2,
            $message->properties['application_headers']->getNativeData()['x-schema-version'],
        );
    }

    public function testReportsPublicationTelemetry(): void
    {
        putenv('MB_RABBITMQ_EXCHANGE=events');

        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $channel->expects(self::once())->method('exchange_declare');
        $channel->expects(self::once())->method('basic_publish');
        $channel->expects(self::once())->method('close');

        $connection = $this->getMockBuilder(AbstractConnection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $connection->expects(self::once())->method('channel')->willReturn($channel);
        $connection->expects(self::once())->method('close');

        \Mirabel\RabbitMQ\Observability\TelemetryRuntime::reset();
        $event = new TestEvent(
            new StubConnectionFactory($connection),
            ['id' => 42],
        );

        $event->publish(messageId: 'event-42');

        self::assertSame(1, \Mirabel\RabbitMQ\Observability\TelemetryRuntime::metrics()->count('published'));
    }

    public function testReusesConnectionForHighVolumePublishing(): void
    {
        putenv('MB_RABBITMQ_EXCHANGE=events');
        putenv('MB_RABBITMQ_REUSE_CONNECTION=true');

        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $channel->expects(self::once())->method('exchange_declare');
        $channel->expects(self::exactly(2))->method('basic_publish');
        $channel->expects(self::once())->method('close');

        $connection = $this->getMockBuilder(AbstractConnection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $connection->expects(self::once())->method('channel')->willReturn($channel);
        $connection->expects(self::once())->method('close');

        $factory = new StubConnectionFactory($connection);
        (new TestEvent($factory, ['id' => 1]))->publish(messageId: 'event-1');
        (new TestEvent($factory, ['id' => 2]))->publish(messageId: 'event-2');
        PublisherRuntime::closeAll();
    }

    public function testPublishRetriesAreBoundedEvenWhenWorkersReconnectForever(): void
    {
        putenv('MB_RABBITMQ_RECONNECT_ATTEMPTS=0');
        putenv('MB_RABBITMQ_RECONNECT_DELAY_MS=0');
        putenv('MB_RABBITMQ_RECONNECT_MAX_DELAY_MS=0');
        putenv('MB_RABBITMQ_PUBLISH_RETRIES=2');

        $factory = new FailingConnectionFactory();

        try {
            (new TestEvent($factory, ['id' => 1]))->publish();
            self::fail('Publishing to an unreachable broker must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('broker down', $exception->getMessage());
        }

        self::assertSame(3, $factory->calls);
    }

    public function testNackedPublisherConfirmIsReportedAsFailure(): void
    {
        putenv('MB_RABBITMQ_PUBLISHER_CONFIRMS=true');
        putenv('MB_RABBITMQ_PUBLISH_RETRIES=0');

        $nackHandler = null;
        $channel = $this->getMockBuilder(AMQPChannel::class)->disableOriginalConstructor()->getMock();
        $channel->expects(self::once())->method('confirm_select');
        $channel->method('set_nack_handler')->willReturnCallback(static function (callable $handler) use (&$nackHandler): void {
            $nackHandler = $handler;
        });
        $channel->method('wait_for_pending_acks')->willReturnCallback(static function () use (&$nackHandler): void {
            $nackHandler(new AMQPMessage('{}'));
        });

        $connection = $this->getMockBuilder(AbstractConnection::class)->disableOriginalConstructor()->getMock();
        $connection->method('channel')->willReturn($channel);

        $this->expectException(PublishNotConfirmedException::class);
        (new TestEvent(new StubConnectionFactory($connection), ['id' => 1]))->publish();
    }

    public function testCloseFailureDoesNotHideTheOriginalError(): void
    {
        putenv('MB_RABBITMQ_PUBLISH_RETRIES=0');

        $channel = $this->getMockBuilder(AMQPChannel::class)->disableOriginalConstructor()->getMock();
        $channel->method('basic_publish')->willThrowException(new \RuntimeException('socket closed while publishing'));
        $channel->method('close')->willThrowException(new \RuntimeException('cannot close a broken channel'));

        $connection = $this->getMockBuilder(AbstractConnection::class)->disableOriginalConstructor()->getMock();
        $connection->method('channel')->willReturn($channel);
        $connection->method('close')->willThrowException(new \RuntimeException('cannot close a broken connection'));

        $this->expectExceptionMessage('socket closed while publishing');
        (new TestEvent(new StubConnectionFactory($connection), ['id' => 1]))->publish();
    }

    public function testEventWithoutRoutingKeyExplainsWhatIsMissing(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('public static string $routingKey');

        (new EventWithoutRoutingKey(['id' => 1]))->toOutboxMessage();
    }
}

final class FailingConnectionFactory implements ConnectionFactoryInterface
{
    public int $calls = 0;

    public function connect(ConnectionConfig $config): AbstractConnection
    {
        $this->calls++;
        throw new \RuntimeException('broker down');
    }
}

final class EventWithoutRoutingKey extends Event
{
}

final class StubConnectionFactory implements ConnectionFactoryInterface
{
    public function __construct(private readonly AbstractConnection $connection)
    {
    }

    public function connect(ConnectionConfig $config): AbstractConnection
    {
        return $this->connection;
    }
}

final class SequenceConnectionFactory implements ConnectionFactoryInterface
{
    /** @param list<AbstractConnection> $connections */
    public function __construct(private array $connections)
    {
    }

    public function connect(ConnectionConfig $config): AbstractConnection
    {
        return array_shift($this->connections);
    }
}

final class TestEvent extends Event
{
    public static string $routingKey = 'orders.received';

    public function __construct(
        private readonly ConnectionFactoryInterface $factory,
        mixed $payload,
    ) {
        parent::__construct($payload);
    }

    protected function connectionFactory(): ConnectionFactoryInterface
    {
        return $this->factory;
    }

}
