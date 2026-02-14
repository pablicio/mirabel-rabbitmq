<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests demonstrating basic usage patterns
 * 
 * Note: These tests require a running RabbitMQ instance
 * Run with: docker run -d --hostname my-rabbit --name rabbitmq -p 5672:5672 -p 15672:15672 rabbitmq:3-management
 */
class BasicUsageTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        $this->config = [
            'default' => 'default',
            'connections' => [
                'default' => [
                    'host' => getenv('RABBITMQ_HOST') ?: 'localhost',
                    'port' => (int) (getenv('RABBITMQ_PORT') ?: 5672),
                    'user' => getenv('RABBITMQ_USER') ?: 'guest',
                    'password' => getenv('RABBITMQ_PASSWORD') ?: 'guest',
                    'vhost' => getenv('RABBITMQ_VHOST') ?: '/',
                    'exchange' => [
                        'name' => 'test-exchange',
                        'type' => 'topic',
                        'durable' => true,
                        'auto_delete' => false,
                    ],
                    'queue' => [
                        'durable' => true,
                        'auto_delete' => false,
                    ],
                    'qos' => [
                        'prefetch_count' => 1,
                    ],
                ],
            ],
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'initial_delay' => 1000,
            ],
            'logging' => [
                'enabled' => false,
            ],
        ];
    }

    public function testBasicPublishAndConsume(): void
    {
        $this->markTestSkipped('Requires RabbitMQ connection. Run manually with RabbitMQ running.');
        
        // This is an example of how to use the library
        // Uncomment and run with RabbitMQ available
        
        /*
        use Pablicio\MirabelRabbitmq\ConnectionManager;
        use Pablicio\MirabelRabbitmq\Publisher;
        use Pablicio\MirabelRabbitmq\Consumer;
        use Pablicio\MirabelRabbitmq\Serializers\JsonSerializer;

        $connectionManager = new ConnectionManager($this->config);
        $serializer = new JsonSerializer();
        
        // Publish a message
        $publisher = new Publisher($connectionManager, $serializer, $this->config);
        $result = $publisher->publish('test.message', [
            'user_id' => 123,
            'action' => 'test',
            'timestamp' => time(),
        ]);
        
        $this->assertTrue($result);
        
        // Consume the message
        $received = false;
        $consumer = new Consumer($connectionManager, $serializer, $this->config);
        
        $consumer->bindKeys(['test.*'])
            ->consume('test-queue', function ($payload) use (&$received) {
                $received = true;
                $this->assertArrayHasKey('user_id', $payload);
                $this->assertEquals(123, $payload['user_id']);
                return 'ack';
            });
        
        $this->assertTrue($received);
        */
    }

    public function testConfiguration(): void
    {
        $this->assertArrayHasKey('connections', $this->config);
        $this->assertArrayHasKey('default', $this->config['connections']);
        $this->assertEquals('test-exchange', $this->config['connections']['default']['exchange']['name']);
    }
}
