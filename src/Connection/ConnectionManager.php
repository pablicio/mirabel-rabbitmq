<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq\Connection;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use Pablicio\MirabelRabbitmq\Exceptions\RabbitMQException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ConnectionManager
{
    private ?AMQPStreamConnection $connection = null;
    private array $config;
    private LoggerInterface $logger;
    private int $reconnectAttempts = 0;
    private int $maxReconnectAttempts;
    private int $reconnectDelay;
    private bool $isReconnecting = false;
    private array $connectionCallbacks = [];
    private array $disconnectionCallbacks = [];

    public function __construct(
        array $config,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
        $this->maxReconnectAttempts = $config['max_reconnect_attempts'] ?? 5;
        $this->reconnectDelay = $config['reconnect_delay'] ?? 2;
    }

    /**
     * Obtém a conexão, reconectando se necessário
     */
    public function getConnection(): AMQPStreamConnection
    {
        if ($this->connection === null || !$this->isConnected()) {
            $this->connect();
        }

        return $this->connection;
    }

    /**
     * Estabelece conexão com RabbitMQ
     */
    public function connect(): void
    {
        try {
            $this->logger->info('Connecting to RabbitMQ...', [
                'host' => $this->config['host'],
                'port' => $this->config['port'],
            ]);

            $this->connection = new AMQPStreamConnection(
                $this->config['host'],
                $this->config['port'],
                $this->config['user'],
                $this->config['password'],
                $this->config['vhost'] ?? '/',
                $this->config['insist'] ?? false,
                $this->config['login_method'] ?? 'AMQPLAIN',
                $this->config['login_response'] ?? null,
                $this->config['locale'] ?? 'en_US',
                $this->config['connection_timeout'] ?? 3.0,
                $this->config['read_write_timeout'] ?? 3.0,
                $this->config['context'] ?? null,
                $this->config['keepalive'] ?? true,
                $this->config['heartbeat'] ?? 60,
                $this->config['channel_rpc_timeout'] ?? 0.0,
                $this->config['ssl_protocol'] ?? null
            );

            $this->reconnectAttempts = 0;
            $this->isReconnecting = false;

            $this->logger->info('Successfully connected to RabbitMQ');

            // Executa callbacks de conexão
            $this->executeCallbacks($this->connectionCallbacks);

        } catch (AMQPRuntimeException $e) {
            $this->logger->error('Failed to connect to RabbitMQ', [
                'error' => $e->getMessage(),
            ]);

            throw RabbitMQException::connectionFailed(
                $this->config['host'] . ':' . $this->config['port'],
                $e->getMessage()
            );
        }
    }

    /**
     * Tenta reconectar automaticamente
     */
    public function reconnect(): bool
    {
        if ($this->isReconnecting) {
            return false;
        }

        $this->isReconnecting = true;

        while ($this->reconnectAttempts < $this->maxReconnectAttempts) {
            $this->reconnectAttempts++;

            $this->logger->warning('Attempting to reconnect to RabbitMQ', [
                'attempt' => $this->reconnectAttempts,
                'max_attempts' => $this->maxReconnectAttempts,
            ]);

            try {
                $this->closeConnection();
                sleep($this->reconnectDelay);
                $this->connect();

                $this->logger->info('Successfully reconnected to RabbitMQ');
                return true;

            } catch (\Exception $e) {
                $this->logger->error('Reconnection attempt failed', [
                    'attempt' => $this->reconnectAttempts,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->isReconnecting = false;
        $this->logger->critical('Failed to reconnect after maximum attempts');

        return false;
    }

    /**
     * Verifica se a conexão está ativa
     */
    public function isConnected(): bool
    {
        if ($this->connection === null) {
            return false;
        }

        try {
            return $this->connection->isConnected();
        } catch (\Exception $e) {
            $this->logger->warning('Error checking connection status', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Fecha a conexão
     */
    public function closeConnection(): void
    {
        if ($this->connection !== null) {
            try {
                $this->logger->info('Closing RabbitMQ connection');
                $this->connection->close();
                
                // Executa callbacks de desconexão
                $this->executeCallbacks($this->disconnectionCallbacks);
                
            } catch (\Exception $e) {
                $this->logger->warning('Error closing connection', [
                    'error' => $e->getMessage(),
                ]);
            } finally {
                $this->connection = null;
            }
        }
    }

    /**
     * Registra callback para quando conectar
     */
    public function onConnection(callable $callback): void
    {
        $this->connectionCallbacks[] = $callback;
    }

    /**
     * Registra callback para quando desconectar
     */
    public function onDisconnection(callable $callback): void
    {
        $this->disconnectionCallbacks[] = $callback;
    }

    /**
     * Executa callbacks registrados
     */
    private function executeCallbacks(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            try {
                $callback($this->connection);
            } catch (\Exception $e) {
                $this->logger->error('Error executing callback', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Retorna estatísticas da conexão
     */
    public function getStats(): array
    {
        return [
            'is_connected' => $this->isConnected(),
            'reconnect_attempts' => $this->reconnectAttempts,
            'max_reconnect_attempts' => $this->maxReconnectAttempts,
            'is_reconnecting' => $this->isReconnecting,
            'host' => $this->config['host'],
            'port' => $this->config['port'],
            'vhost' => $this->config['vhost'] ?? '/',
        ];
    }

    /**
     * Destrutor - garante fechamento da conexão
     */
    public function __destruct()
    {
        $this->closeConnection();
    }
}
