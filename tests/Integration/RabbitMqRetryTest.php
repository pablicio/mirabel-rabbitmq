<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Tests\Integration;

use Mirabel\RabbitMQ\Event;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;

final class RabbitMqRetryTest extends TestCase
{
    private ?AMQPStreamConnection $connection = null;

    protected function setUp(): void
    {
        if (getenv('MIRABEL_RABBITMQ_INTEGRATION') !== '1') {
            self::markTestSkipped('Set MIRABEL_RABBITMQ_INTEGRATION=1 to run RabbitMQ integration tests.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->connection->close();
        }
    }

    public function testMessageReturnsFromRetryQueueWithXDeathMetadata(): void
    {
        $exchange = 'mirabel.test.' . bin2hex(random_bytes(6));
        $queue = $exchange . '.queue';
        $retryExchange = $queue . '.retry';
        $retryQueue = $queue . '.retry';
        $routingKey = 'integration.test';

        putenv('MB_RABBITMQ_EXCHANGE=' . $exchange);

        $this->connection = new AMQPStreamConnection(
            getenv('MB_RABBITMQ_HOST') ?: '127.0.0.1',
            (int) (getenv('MB_RABBITMQ_PORT') ?: 5672),
            getenv('MB_RABBITMQ_USER') ?: 'guest',
            getenv('MB_RABBITMQ_PASSWORD') ?: 'guest',
            getenv('MB_RABBITMQ_VHOST') ?: '/',
        );
        $channel = $this->connection->channel();

        $channel->exchange_declare($exchange, 'topic', false, true, false);
        $channel->exchange_declare($retryExchange, 'topic', false, true, false);
        $channel->queue_declare($queue, false, false, true, false, false, [
            'x-dead-letter-exchange' => ['S', $retryExchange],
            'x-dead-letter-routing-key' => ['S', $queue],
        ]);
        $channel->queue_declare($retryQueue, false, false, true, false, false, [
            'x-message-ttl' => ['I', 100],
            'x-dead-letter-exchange' => ['S', $exchange],
            'x-dead-letter-routing-key' => ['S', $routingKey],
        ]);
        $channel->queue_bind($queue, $exchange, $routingKey);
        $channel->queue_bind($retryQueue, $retryExchange, $queue);

        (new IntegrationEvent(['id' => 1]))->publish($routingKey);

        $firstDeliveryReceived = false;
        $consumerTag = $channel->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($channel, &$firstDeliveryReceived): void {
                $firstDeliveryReceived = true;
                $channel->basic_nack($message->getDeliveryTag(), false, false);
            },
        );

        while (!$firstDeliveryReceived) {
            $channel->wait(null, false, 5);
        }
        $channel->basic_cancel($consumerTag);

        $returnedMessage = null;
        $consumerTag = $channel->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($channel, &$returnedMessage): void {
                $returnedMessage = $message;
                $channel->basic_ack($message->getDeliveryTag());
            },
        );

        while ($returnedMessage === null) {
            $channel->wait(null, false, 5);
        }
        $channel->basic_cancel($consumerTag);

        $deaths = $returnedMessage->get('application_headers')->getNativeData()['x-death'] ?? [];
        $retryDeath = array_values(array_filter(
            $deaths,
            static fn (array $death): bool => ($death['queue'] ?? null) === $retryQueue,
        ));

        self::assertNotEmpty($retryDeath);
        self::assertGreaterThanOrEqual(1, $retryDeath[0]['count'] ?? 0);
    }
}

final class IntegrationEvent extends Event
{
    public static string $routingKey = 'integration.test';
}
