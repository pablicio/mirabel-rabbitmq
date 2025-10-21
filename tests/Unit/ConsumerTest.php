<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pablicio\MirabelRabbitmq\Consumer;
use Pablicio\MirabelRabbitmq\Contracts\ConnectionManagerInterface;
use Pablicio\MirabelRabbitmq\Contracts\SerializerInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;

class ConsumerTest extends TestCase
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
                    'queue' => [
                        'passive' => false,
                        'durable' => true,
                        'exclusive' => false,
                        'auto_delete' => false,
                    ],
                    'qos' => [
                        'prefetch_size' => 0,
                        'prefetch_count' => 1,
                        'global' => false,
                    ],
                    'consumer' => [
                        'tag' => '',
                        'no_local' => false,
                        'no_ack' => false,
                        'exclusive' => false,
                        'nowait' => false,
                    ],
                ],
            ],
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'initial_delay' => 1000,
            ],
            'dead_letter' => [
                'enabled' => true,
            ],
            'logging' => [
                'enabled' => true,
            ],
        ];
    }

    public function testBindKeysMethod(): void
    {
        $consumer = new Consumer($this->connectionManager, $this->serializer, $this->config, $this->logger);
        $routingKeys = ['user.*', 'order.created'];

        $result = $consumer->bindKeys($routingKeys);

        $this->assertInstanceOf(Consumer::class, $result);
    }

    public function testConnectionMethod(): void
    {
        $consumer = new Consumer($this->connectionManager, $this->serializer, $this->config, $this->logger);
        $result = $consumer->connection('custom');

        $this->assertInstanceOf(Consumer::class, $result);
    }

    public function testWithRetryMethod(): void
    {
        $consumer = new Consumer($this->connectionManager, $this->serializer, $this->config, $this->logger);
        $result = $consumer->withRetry(5, 2000);

        $this->assertInstanceOf(Consumer::class, $result);
    }

    public function testStopMethod(): void
    {
        $consumer = new Consumer($this->connectionManager, $this->serializer, $this->config, $this->logger);
        
        // Should not throw exception
        $consumer->stop();
        
        $this->assertTrue(true);
    }
}
