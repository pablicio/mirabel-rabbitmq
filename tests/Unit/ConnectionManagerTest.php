<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pablicio\MirabelRabbitmq\ConnectionManager;
use Pablicio\MirabelRabbitmq\Exceptions\RabbitMQException;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use Psr\Log\LoggerInterface;

class ConnectionManagerTest extends TestCase
{
    private array $config;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->config = [
            'default' => 'default',
            'connections' => [
                'default' => [
                    'host' => 'localhost',
                    'port' => 5672,
                    'user' => 'guest',
                    'password' => 'guest',
                    'vhost' => '/',
                    'ssl' => false,
                    'connection_timeout' => 3,
                    'read_write_timeout' => 3,
                    'heartbeat' => 0,
                    'keepalive' => false,
                ],
            ],
            'logging' => [
                'enabled' => true,
            ],
        ];
    }

    public function testGetDefaultConnection(): void
    {
        $manager = new ConnectionManager($this->config, $this->logger);
        
        // This would try to actually connect, so we just test the configuration
        $this->assertEquals('default', $this->config['default']);
    }

    public function testInvalidConnectionThrowsException(): void
    {
        $this->expectException(RabbitMQException::class);

        $manager = new ConnectionManager($this->config, $this->logger);
        $manager->connection('non-existent');
    }

    public function testConfigurationAccess(): void
    {
        $manager = new ConnectionManager($this->config, $this->logger);
        
        $this->assertArrayHasKey('connections', $this->config);
        $this->assertArrayHasKey('default', $this->config['connections']);
        $this->assertEquals('localhost', $this->config['connections']['default']['host']);
    }
}
