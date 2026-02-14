<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pablicio\MirabelRabbitmq\Publisher;
use Pablicio\MirabelRabbitmq\Contracts\ConnectionManagerInterface;
use Pablicio\MirabelRabbitmq\Contracts\SerializerInterface;
use Pablicio\MirabelRabbitmq\Exceptions\RabbitMQException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

class PublisherTest extends TestCase
{
    private ConnectionManagerInterface $connectionManager;
    private SerializerInterface $serializer;
    private AMQPChannel $channel;
    private LoggerInterface $logger;
    private array $config;

    protected function setUp(): void
    {
        $this->connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->channel = $this->createMock(AMQPChannel::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->config = [
            'default' => 'default',
            'connections' => [
                'default' => [
                    'exchange' => [
                        'name' => 'test-exchange',
                        'type' => 'topic',
                        'passive' => false,
                        'durable' => true,
                        'auto_delete' => false,
                        'internal' => false,
                        'nowait' => false,
                        'arguments' => [],
                    ],
                ],
            ],
            'logging' => [
                'enabled' => true,
            ],
        ];
    }

    public function testPublishMessageSuccessfully(): void
    {
        $routingKey = 'user.created';
        $payload = ['id' => 1, 'name' => 'Test User'];
        $serializedData = json_encode($payload);

        $this->connectionManager
            ->expects($this->once())
            ->method('channel')
            ->with('default')
            ->willReturn($this->channel);

        $this->serializer
            ->expects($this->once())
            ->method('serialize')
            ->with($payload)
            ->willReturn($serializedData);

        $this->serializer
            ->expects($this->once())
            ->method('getContentType')
            ->willReturn('application/json');

        $this->channel
            ->expects($this->once())
            ->method('exchange_declare')
            ->with(
                'test-exchange',
                'topic',
                false,
                true,
                false,
                false,
                false,
                $this->anything()
            );

        $this->channel
            ->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(function ($message) use ($serializedData) {
                    return $message instanceof AMQPMessage && $message->body === $serializedData;
                }),
                'test-exchange',
                $routingKey
            );

        $publisher = new Publisher($this->connectionManager, $this->serializer, $this->config, $this->logger);
        $result = $publisher->publish($routingKey, $payload);

        $this->assertTrue($result);
    }

    public function testPublishToCustomExchange(): void
    {
        $exchange = 'custom-exchange';
        $routingKey = 'order.created';
        $payload = ['order_id' => 123];

        $this->connectionManager
            ->expects($this->once())
            ->method('channel')
            ->willReturn($this->channel);

        $this->serializer
            ->expects($this->once())
            ->method('serialize')
            ->willReturn('{}');

        $this->serializer
            ->expects($this->once())
            ->method('getContentType')
            ->willReturn('application/json');

        $this->channel
            ->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->anything(),
                $exchange,
                $routingKey
            );

        $publisher = new Publisher($this->connectionManager, $this->serializer, $this->config, $this->logger);
        $publisher->toExchange($exchange)->publish($routingKey, $payload);
    }

    public function testPublishWithCustomConnection(): void
    {
        $connectionName = 'custom';
        $routingKey = 'test.event';
        $payload = ['data' => 'test'];

        $this->config['connections']['custom'] = $this->config['connections']['default'];

        $this->connectionManager
            ->expects($this->once())
            ->method('channel')
            ->with($connectionName)
            ->willReturn($this->channel);

        $this->serializer
            ->expects($this->once())
            ->method('serialize')
            ->willReturn('{}');

        $this->serializer
            ->expects($this->once())
            ->method('getContentType')
            ->willReturn('application/json');

        $publisher = new Publisher($this->connectionManager, $this->serializer, $this->config, $this->logger);
        $publisher->connection($connectionName)->publish($routingKey, $payload);
    }

    public function testPublishWithCustomProperties(): void
    {
        $routingKey = 'test.route';
        $payload = ['test' => 'data'];
        $customProperties = [
            'priority' => 5,
            'expiration' => '60000',
            'application_headers' => [
                'x-custom' => 'value',
            ],
        ];

        $this->connectionManager
            ->expects($this->once())
            ->method('channel')
            ->willReturn($this->channel);

        $this->serializer
            ->expects($this->once())
            ->method('serialize')
            ->willReturn('{}');

        $this->serializer
            ->expects($this->once())
            ->method('getContentType')
            ->willReturn('application/json');

        $this->channel
            ->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(function ($message) use ($customProperties) {
                    $props = $message->get_properties();
                    return isset($props['priority']) && $props['priority'] === 5
                        && isset($props['expiration']) && $props['expiration'] === '60000';
                }),
                $this->anything(),
                $routingKey
            );

        $publisher = new Publisher($this->connectionManager, $this->serializer, $this->config, $this->logger);
        $publisher->publish($routingKey, $payload, $customProperties);
    }

    public function testPublishThrowsExceptionOnFailure(): void
    {
        $this->expectException(RabbitMQException::class);

        $this->connectionManager
            ->expects($this->once())
            ->method('channel')
            ->willThrowException(new \Exception('Connection failed'));

        $publisher = new Publisher($this->connectionManager, $this->serializer, $this->config, $this->logger);
        $publisher->publish('test.route', ['data' => 'test']);
    }
}
