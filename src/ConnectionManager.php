<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq;

use Illuminate\Support\Arr;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use Pablicio\MirabelRabbitmq\Contracts\ConnectionManagerInterface;
use Pablicio\MirabelRabbitmq\Exceptions\RabbitMQException;
use Psr\Log\LoggerInterface;

class ConnectionManager implements ConnectionManagerInterface
{
    /**
     * @var array<string, AbstractConnection>
     */
    private array $connections = [];

    /**
     * @var array<string, AMQPChannel>
     */
    private array $channels = [];

    public function __construct(
        private readonly array $config,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function connection(?string $name = null): AbstractConnection
    {
        $name = $name ?? $this->getDefaultConnection();

        if (isset($this->connections[$name])) {
            if ($this->isConnectionAlive($this->connections[$name])) {
                return $this->connections[$name];
            }
            
            $this->log('info', "Connection [{$name}] is stale, reconnecting...");
            unset($this->connections[$name]);
        }

        return $this->connections[$name] = $this->createConnection($name);
    }

    public function channel(?string $connection = null): AMQPChannel
    {
        $connectionName = $connection ?? $this->getDefaultConnection();
        $cacheKey = $connectionName;

        if (isset($this->channels[$cacheKey]) && $this->channels[$cacheKey]->is_open()) {
            return $this->channels[$cacheKey];
        }

        $conn = $this->connection($connectionName);
        $this->channels[$cacheKey] = $conn->channel();

        $this->log('debug', "Created new channel for connection [{$connectionName}]");

        return $this->channels[$cacheKey];
    }

    public function disconnect(?string $name = null): void
    {
        if ($name === null) {
            foreach (array_keys($this->connections) as $connectionName) {
                $this->disconnect($connectionName);
            }
            return;
        }

        if (isset($this->channels[$name]) && $this->channels[$name]->is_open()) {
            $this->channels[$name]->close();
            unset($this->channels[$name]);
        }

        if (isset($this->connections[$name]) && $this->connections[$name]->isConnected()) {
            $this->connections[$name]->close();
            unset($this->connections[$name]);
            $this->log('info', "Disconnected from [{$name}]");
        }
    }

    public function reconnect(?string $name = null): AbstractConnection
    {
        $name = $name ?? $this->getDefaultConnection();
        $this->disconnect($name);
        return $this->connection($name);
    }

    public function isConnected(?string $name = null): bool
    {
        $name = $name ?? $this->getDefaultConnection();
        
        return isset($this->connections[$name]) 
            && $this->isConnectionAlive($this->connections[$name]);
    }

    private function createConnection(string $name): AbstractConnection
    {
        $config = $this->getConnectionConfig($name);

        try {
            $this->log('info', "Creating connection to [{$name}]");

            if ($config['ssl'] ?? false) {
                return new AMQPSSLConnection(
                    $config['host'],
                    $config['port'],
                    $config['user'],
                    $config['password'],
                    $config['vhost'] ?? '/',
                    $config['ssl_options'] ?? [],
                    [
                        'connection_timeout' => $config['connection_timeout'] ?? 3,
                        'read_write_timeout' => $config['read_write_timeout'] ?? 3,
                        'heartbeat' => $config['heartbeat'] ?? 0,
                        'keepalive' => $config['keepalive'] ?? false,
                    ]
                );
            }

            return new AMQPStreamConnection(
                $config['host'],
                $config['port'],
                $config['user'],
                $config['password'],
                $config['vhost'] ?? '/',
                false, // insist
                'AMQPLAIN', // login_method
                null, // login_response
                'en_US', // locale
                $config['connection_timeout'] ?? 3,
                $config['read_write_timeout'] ?? 3,
                null, // context
                $config['keepalive'] ?? false,
                $config['heartbeat'] ?? 0
            );
        } catch (\Exception $e) {
            $this->log('error', "Failed to create connection [{$name}]: {$e->getMessage()}");
            throw RabbitMQException::connectionFailed($name, $e->getMessage());
        }
    }

    private function getConnectionConfig(string $name): array
    {
        $config = Arr::get($this->config, "connections.{$name}");

        if (!$config) {
            throw RabbitMQException::invalidConfiguration("connections.{$name}");
        }

        return $config;
    }

    private function getDefaultConnection(): string
    {
        return $this->config['default'] ?? 'default';
    }

    private function isConnectionAlive(AbstractConnection $connection): bool
    {
        try {
            return $connection->isConnected();
        } catch (\Exception) {
            return false;
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger && ($this->config['logging']['enabled'] ?? false)) {
            $this->logger->log($level, "[MirabelRabbitMQ] {$message}", $context);
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
