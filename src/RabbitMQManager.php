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
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Gerenciador principal do RabbitMQ para Laravel
 * Abstrai toda a complexidade da biblioteca
 */
class RabbitMQManager
{
    private array $clients = [];
    private array $config;
    private SerializerInterface $serializer;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->serializer = new JsonSerializer();
    }

    /**
     * Publica um evento (método simplificado para traits)
     */
    public function publishEvent(
        mixed $payload,
        string $routingKey,
        ?string $exchange = null,
        ?string $connection = null,
        array $options = []
    ): bool {
        $client = $this->getClient($connection);
        $exchange = $exchange ?? $this->getDefaultExchange($connection);

        return $client->publish($payload, $routingKey, $exchange, $options);
    }

    /**
     * Subscribe a um worker (método simplificado para traits)
     */
    public function subscribe(
        string $queue,
        callable $callback,
        array $routingKeys = [],
        array $options = [],
        array $retryOptions = [],
        ?string $connection = null
    ): void {
        $client = $this->getClient($connection);
        $exchange = $this->getDefaultExchange($connection);

        // Cria fila com DLQ se retry está configurado
        if (!empty($retryOptions)) {
            $dlqOptions = [
                'durable' => true,
                'message_ttl' => $retryOptions['x-message-ttl'] ?? 5000,
            ];
            
            $client->createQueueWithDLQ($queue, $dlqOptions);
        } else {
            $client->declareQueue($queue, ['durable' => true]);
        }

        // Faz bind das routing keys
        foreach ($routingKeys as $routingKey) {
            $client->bindQueue($queue, $exchange, $routingKey);
        }

        // Configura opções de consumo
        $consumeOptions = array_merge([
            'prefetch_count' => 1,
            'timeout' => 0,
        ], $options);

        // Se tem retry options, adiciona
        if (!empty($retryOptions)) {
            $consumeOptions['max_retries'] = $retryOptions['max-attempts'] ?? 3;
            $consumeOptions['retry_delay'] = $retryOptions['x-message-ttl'] ?? 5000;
            $consumeOptions['error_strategy'] = 'reject_to_dlq';
        }

        // Consome mensagens
        $client->consume($queue, $callback, $consumeOptions);
    }

    /**
     * Métodos proxy para RabbitMQClient
     */
    public function publish(mixed $message, string $routingKey, ?string $exchange = null, array $options = []): bool
    {
        return $this->getClient()->publish($message, $routingKey, $exchange, $options);
    }

    public function publishBatch(array $messages, ?string $exchange = null, ?callable $routingKeyResolver = null, array $options = []): array
    {
        return $this->getClient()->publishBatch($messages, $exchange, $routingKeyResolver, $options);
    }

    public function consume(string $queue, callable $callback, array $options = []): void
    {
        $this->getClient()->consume($queue, $callback, $options);
    }

    public function declareExchange(string $name, string $type = 'direct', array $options = []): void
    {
        $this->getClient()->declareExchange($name, $type, $options);
    }

    public function declareQueue(string $name, array $options = []): array
    {
        return $this->getClient()->declareQueue($name, $options);
    }

    public function bindQueue(string $queue, string $exchange, string $routingKey = '', array $arguments = []): void
    {
        $this->getClient()->bindQueue($queue, $exchange, $routingKey, $arguments);
    }

    public function createQueueWithDLQ(string $queueName, array $options = []): array
    {
        return $this->getClient()->createQueueWithDLQ($queueName, $options);
    }

    public function setQoS(int $prefetchCount = 1, int $prefetchSize = 0, bool $global = false, ?string $channelId = null): void
    {
        $this->getClient()->setQoS($prefetchCount, $prefetchSize, $global, $channelId);
    }

    public function getStats(): array
    {
        return $this->getClient()->getStats();
    }

    /**
     * Obtém ou cria um cliente para uma conexão específica
     */
    private function getClient(?string $connection = null): RabbitMQClient
    {
        $connection = $connection ?? $this->config['default'] ?? 'default';

        if (!isset($this->clients[$connection])) {
            $connectionConfig = $this->config['connections'][$connection] ?? [];
            
            if (empty($connectionConfig)) {
                throw new \InvalidArgumentException("RabbitMQ connection [{$connection}] not configured");
            }

            // Cria o cliente com configurações da conexão
            $this->clients[$connection] = RabbitMQClientBuilder::create()
                ->fromArray($connectionConfig)
                ->serializer($this->serializer)
                ->logger(Log::getLogger())
                ->build();
        }

        return $this->clients[$connection];
    }

    /**
     * Obtém o exchange padrão para uma conexão
     */
    private function getDefaultExchange(?string $connection = null): string
    {
        $connection = $connection ?? $this->config['default'] ?? 'default';
        return $this->config['connections'][$connection]['exchange'] ?? '';
    }

    /**
     * Fecha todas as conexões
     */
    public function disconnect(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        
        $this->clients = [];
    }

    /**
     * Destrutor
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
