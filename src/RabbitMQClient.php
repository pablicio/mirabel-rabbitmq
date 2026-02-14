<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq;

use Pablicio\MirabelRabbitmq\Connection\ConnectionManager;
use Pablicio\MirabelRabbitmq\Connection\ChannelPool;
use Pablicio\MirabelRabbitmq\Publishers\ConfirmedPublisher;
use Pablicio\MirabelRabbitmq\Consumers\Consumer;
use Pablicio\MirabelRabbitmq\Queue\DeadLetterQueueManager;
use Pablicio\MirabelRabbitmq\Contracts\SerializerInterface;
use Pablicio\MirabelRabbitmq\Serializers\JsonSerializer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class RabbitMQClient
{
    private ConnectionManager $connectionManager;
    private ChannelPool $channelPool;
    private ConfirmedPublisher $publisher;
    private Consumer $consumer;
    private DeadLetterQueueManager $dlqManager;
    private SerializerInterface $serializer;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        array $config,
        ?SerializerInterface $serializer = null,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->serializer = $serializer ?? new JsonSerializer();
        $this->logger = $logger ?? new NullLogger();

        // Inicializa componentes
        $this->connectionManager = new ConnectionManager($config, $this->logger);
        $this->channelPool = new ChannelPool(
            $this->connectionManager,
            $config['max_channels'] ?? 10,
            $this->logger
        );
        $this->publisher = new ConfirmedPublisher($this->channelPool, $this->serializer, $this->logger);
        $this->consumer = new Consumer($this->channelPool, $this->serializer, $this->logger);
        $this->dlqManager = new DeadLetterQueueManager($this->channelPool, $this->logger);
    }

    /**
     * Publica uma mensagem simples
     */
    public function publish(
        mixed $message,
        string $routingKey,
        ?string $exchange = null,
        array $options = []
    ): bool {
        $exchange = $exchange ?? $this->config['default_exchange'] ?? '';
        
        return $this->publisher->publish($message, $exchange, $routingKey, $options);
    }

    /**
     * Publica mensagens em lote
     */
    public function publishBatch(
        array $messages,
        ?string $exchange = null,
        ?callable $routingKeyResolver = null,
        array $options = []
    ): array {
        $exchange = $exchange ?? $this->config['default_exchange'] ?? '';
        
        return $this->publisher->publishBatch($messages, $exchange, $routingKeyResolver, $options);
    }

    /**
     * Consome mensagens de uma fila
     */
    public function consume(
        string $queue,
        callable $callback,
        array $options = []
    ): void {
        $this->consumer->consume($queue, $callback, $options);
    }

    /**
     * Declara um exchange
     */
    public function declareExchange(
        string $name,
        string $type = 'direct',
        array $options = []
    ): void {
        $channel = $this->channelPool->getChannel('declare');
        
        $channel->exchange_declare(
            $name,
            $type,
            $options['passive'] ?? false,
            $options['durable'] ?? true,
            $options['auto_delete'] ?? false,
            $options['internal'] ?? false,
            $options['nowait'] ?? false,
            $options['arguments'] ?? []
        );

        $this->logger->info('Exchange declared', [
            'name' => $name,
            'type' => $type,
        ]);
    }

    /**
     * Declara uma fila
     */
    public function declareQueue(
        string $name,
        array $options = []
    ): array {
        $channel = $this->channelPool->getChannel('declare');
        
        [$queue, $messageCount, $consumerCount] = $channel->queue_declare(
            $name,
            $options['passive'] ?? false,
            $options['durable'] ?? true,
            $options['exclusive'] ?? false,
            $options['auto_delete'] ?? false,
            $options['nowait'] ?? false,
            $options['arguments'] ?? null
        );

        $this->logger->info('Queue declared', [
            'name' => $name,
            'message_count' => $messageCount,
            'consumer_count' => $consumerCount,
        ]);

        return [
            'queue' => $queue,
            'message_count' => $messageCount,
            'consumer_count' => $consumerCount,
        ];
    }

    /**
     * Faz bind de uma fila a um exchange
     */
    public function bindQueue(
        string $queue,
        string $exchange,
        string $routingKey = '',
        array $arguments = []
    ): void {
        $channel = $this->channelPool->getChannel('declare');
        
        $channel->queue_bind($queue, $exchange, $routingKey, false, $arguments);

        $this->logger->info('Queue bound to exchange', [
            'queue' => $queue,
            'exchange' => $exchange,
            'routing_key' => $routingKey,
        ]);
    }

    /**
     * Cria uma fila com DLQ (Dead Letter Queue)
     */
    public function createQueueWithDLQ(
        string $queueName,
        array $options = []
    ): array {
        return $this->dlqManager->createQueueWithDLX($queueName, $options);
    }

    /**
     * Configura Quality of Service (QoS)
     */
    public function setQoS(
        int $prefetchCount = 1,
        int $prefetchSize = 0,
        bool $global = false,
        ?string $channelId = null
    ): void {
        $channel = $this->channelPool->getChannel($channelId ?? 'qos');
        $channel->basic_qos($prefetchSize, $prefetchCount, $global);

        $this->logger->info('QoS configured', [
            'prefetch_count' => $prefetchCount,
            'prefetch_size' => $prefetchSize,
            'global' => $global,
        ]);
    }

    /**
     * Obtém estatísticas gerais
     */
    public function getStats(): array
    {
        return [
            'connection' => $this->connectionManager->getStats(),
            'channel_pool' => $this->channelPool->getStats(),
            'publisher' => $this->publisher->getStats(),
            'consumer' => $this->consumer->getStats(),
        ];
    }

    /**
     * Retorna o ConnectionManager
     */
    public function getConnectionManager(): ConnectionManager
    {
        return $this->connectionManager;
    }

    /**
     * Retorna o ChannelPool
     */
    public function getChannelPool(): ChannelPool
    {
        return $this->channelPool;
    }

    /**
     * Retorna o Publisher
     */
    public function getPublisher(): ConfirmedPublisher
    {
        return $this->publisher;
    }

    /**
     * Retorna o Consumer
     */
    public function getConsumer(): Consumer
    {
        return $this->consumer;
    }

    /**
     * Retorna o DLQ Manager
     */
    public function getDLQManager(): DeadLetterQueueManager
    {
        return $this->dlqManager;
    }

    /**
     * Fecha todas as conexões
     */
    public function close(): void
    {
        $this->channelPool->closeAllChannels();
        $this->connectionManager->closeConnection();
        
        $this->logger->info('RabbitMQ client closed');
    }

    /**
     * Destrutor - garante fechamento
     */
    public function __destruct()
    {
        $this->close();
    }
}
