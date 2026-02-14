<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq;

use Closure;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Pablicio\MirabelRabbitmq\Contracts\ConsumerInterface;
use Pablicio\MirabelRabbitmq\Contracts\ConnectionManagerInterface;
use Pablicio\MirabelRabbitmq\Contracts\SerializerInterface;
use Pablicio\MirabelRabbitmq\Exceptions\RabbitMQException;
use Psr\Log\LoggerInterface;

class Consumer implements ConsumerInterface
{
    private array $routingKeys = [];
    private ?string $connectionName = null;
    private bool $retryEnabled = false;
    private int $maxAttempts = 3;
    private int $initialDelay = 1000;
    private bool $shouldStop = false;

    public function __construct(
        private readonly ConnectionManagerInterface $connectionManager,
        private readonly SerializerInterface $serializer,
        private readonly array $config,
        private readonly ?LoggerInterface $logger = null
    ) {
        $this->retryEnabled = $this->config['retry']['enabled'] ?? true;
        $this->maxAttempts = $this->config['retry']['max_attempts'] ?? 3;
        $this->initialDelay = $this->config['retry']['initial_delay'] ?? 1000;
    }

    public function consume(string $queue, Closure $callback, array $options = []): void
    {
        try {
            $connection = $this->connectionName ?? $this->config['default'];
            $channel = $this->connectionManager->channel($connection);

            // Setup queues and exchanges
            $this->setupInfrastructure($connection, $queue, $options);

            // Bind routing keys
            $this->bindRoutingKeys($connection, $queue);

            // Set QoS
            $qosConfig = $this->config['connections'][$connection]['qos'] ?? [];
            $channel->basic_qos(
                $qosConfig['prefetch_size'] ?? 0,
                $qosConfig['prefetch_count'] ?? 1,
                $qosConfig['global'] ?? false
            );

            // Create consumer callback wrapper
            $consumerCallback = $this->createConsumerCallback($queue, $callback, $channel);

            // Start consuming
            $consumerConfig = array_merge(
                $this->config['connections'][$connection]['consumer'] ?? [],
                $options
            );

            $channel->basic_consume(
                $queue,
                $consumerConfig['tag'] ?? '',
                $consumerConfig['no_local'] ?? false,
                $consumerConfig['no_ack'] ?? false,
                $consumerConfig['exclusive'] ?? false,
                $consumerConfig['nowait'] ?? false,
                $consumerCallback
            );

            $this->log('info', "Started consuming from queue [{$queue}]", [
                'queue' => $queue,
                'connection' => $connection,
            ]);

            // Wait for messages
            while ($channel->is_consuming() && !$this->shouldStop) {
                $channel->wait();
            }
        } catch (\Exception $e) {
            $this->log('error', "Consumer error: {$e->getMessage()}", [
                'queue' => $queue,
                'exception' => get_class($e),
            ]);
            
            throw RabbitMQException::consumeFailed($queue, $e->getMessage());
        }
    }

    public function bindKeys(array $routingKeys): self
    {
        $this->routingKeys = $routingKeys;
        return $this;
    }

    public function connection(string $connection): self
    {
        $this->connectionName = $connection;
        return $this;
    }

    public function withRetry(int $maxAttempts = 3, int $initialDelay = 1000): self
    {
        $this->retryEnabled = true;
        $this->maxAttempts = $maxAttempts;
        $this->initialDelay = $initialDelay;
        return $this;
    }

    public function stop(): void
    {
        $this->shouldStop = true;
        $this->log('info', 'Consumer stop requested');
    }

    private function setupInfrastructure(string $connection, string $queue, array $options): void
    {
        $channel = $this->connectionManager->channel($connection);
        $exchangeConfig = $this->config['connections'][$connection]['exchange'] ?? [];
        $queueConfig = array_merge(
            $this->config['connections'][$connection]['queue'] ?? [],
            $options
        );

        // Declare main exchange
        $exchange = $exchangeConfig['name'] ?? 'default';
        $channel->exchange_declare(
            $exchange,
            $exchangeConfig['type'] ?? 'topic',
            $exchangeConfig['passive'] ?? false,
            $exchangeConfig['durable'] ?? true,
            $exchangeConfig['auto_delete'] ?? false,
            $exchangeConfig['internal'] ?? false,
            $exchangeConfig['nowait'] ?? false,
            new AMQPTable($exchangeConfig['arguments'] ?? [])
        );

        // Setup retry mechanism if enabled
        if ($this->retryEnabled) {
            $this->setupRetryInfrastructure($connection, $queue, $queueConfig);
        } else {
            // Declare simple queue
            $channel->queue_declare(
                $queue,
                $queueConfig['passive'] ?? false,
                $queueConfig['durable'] ?? true,
                $queueConfig['exclusive'] ?? false,
                $queueConfig['auto_delete'] ?? false,
                $queueConfig['nowait'] ?? false
            );
        }
    }

    private function setupRetryInfrastructure(string $connection, string $queue, array $queueConfig): void
    {
        $channel = $this->connectionManager->channel($connection);
        $retryQueue = "{$queue}.retry";
        $errorQueue = "{$queue}.error";
        $retryExchange = "{$queue}.retry";
        $errorExchange = "{$queue}.error";

        // Main queue with DLX to retry
        $mainQueueArgs = new AMQPTable([
            'x-dead-letter-exchange' => $retryExchange,
            'x-dead-letter-routing-key' => $retryQueue,
        ]);

        $channel->queue_declare(
            $queue,
            $queueConfig['passive'] ?? false,
            $queueConfig['durable'] ?? true,
            $queueConfig['exclusive'] ?? false,
            $queueConfig['auto_delete'] ?? false,
            $queueConfig['nowait'] ?? false,
            $mainQueueArgs
        );

        // Retry exchange and queue
        $channel->exchange_declare($retryExchange, 'topic', false, true, false);
        
        $retryQueueArgs = new AMQPTable([
            'x-dead-letter-exchange' => $this->config['connections'][$connection]['exchange']['name'] ?? 'default',
            'x-dead-letter-routing-key' => $queue,
            'x-message-ttl' => $this->initialDelay,
        ]);

        $channel->queue_declare($retryQueue, false, true, false, false, false, $retryQueueArgs);
        $channel->queue_bind($retryQueue, $retryExchange, $retryQueue);

        // Error exchange and queue (DLQ)
        if ($this->config['dead_letter']['enabled'] ?? true) {
            $channel->exchange_declare($errorExchange, 'topic', false, true, false);
            $channel->queue_declare($errorQueue, false, true, false, false);
            $channel->queue_bind($errorQueue, $errorExchange, $errorQueue);
        }
    }

    private function bindRoutingKeys(string $connection, string $queue): void
    {
        if (empty($this->routingKeys)) {
            return;
        }

        $channel = $this->connectionManager->channel($connection);
        $exchange = $this->config['connections'][$connection]['exchange']['name'] ?? 'default';

        foreach ($this->routingKeys as $routingKey) {
            $channel->queue_bind($queue, $exchange, $routingKey);
            $this->log('debug', "Bound routing key [{$routingKey}] to queue [{$queue}]");
        }
    }

    private function createConsumerCallback(string $queue, Closure $callback, $channel): Closure
    {
        return function (AMQPMessage $message) use ($queue, $callback, $channel) {
            $startTime = microtime(true);
            
            try {
                // Deserialize message
                $payload = $this->serializer->deserialize($message->body);
                
                // Get retry count from headers
                $retryCount = $this->getRetryCount($message);

                $this->log('debug', "Processing message from queue [{$queue}]", [
                    'queue' => $queue,
                    'message_id' => $message->get('message_id'),
                    'retry_count' => $retryCount,
                ]);

                // Check if max retries exceeded
                if ($this->retryEnabled && $retryCount >= $this->maxAttempts) {
                    $this->handleMaxRetriesExceeded($message, $queue);
                    return;
                }

                // Execute user callback
                $result = $callback($payload, $message);

                // Handle result
                $this->handleCallbackResult($result, $message);

                $duration = microtime(true) - $startTime;
                $this->log('info', "Message processed successfully in {$duration}s", [
                    'queue' => $queue,
                    'duration' => $duration,
                ]);
            } catch (\Exception $e) {
                $this->log('error', "Error processing message: {$e->getMessage()}", [
                    'queue' => $queue,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);

                // Reject and requeue for retry if enabled
                if ($this->retryEnabled) {
                    $message->nack(false, false); // Don't requeue, will go to DLX
                } else {
                    $message->reject(false); // Don't requeue
                }
            }
        };
    }

    private function getRetryCount(AMQPMessage $message): int
    {
        $headers = $message->get('application_headers');
        
        if ($headers instanceof AMQPTable) {
            $data = $headers->getNativeData();
            
            // Check x-death header for retry count
            if (isset($data['x-death']) && is_array($data['x-death'])) {
                foreach ($data['x-death'] as $death) {
                    if (isset($death['count'])) {
                        return (int) $death['count'];
                    }
                }
            }
        }

        return 0;
    }

    private function handleMaxRetriesExceeded(AMQPMessage $message, string $queue): void
    {
        $this->log('warning', "Max retries exceeded for message in queue [{$queue}]", [
            'queue' => $queue,
            'message_id' => $message->get('message_id'),
        ]);

        if ($this->config['dead_letter']['enabled'] ?? true) {
            // Send to error queue
            $errorExchange = "{$queue}.error";
            $errorQueue = "{$queue}.error";
            
            $message->getChannel()->basic_publish(
                $message,
                $errorExchange,
                $errorQueue
            );
        }

        $message->ack();
    }

    private function handleCallbackResult($result, AMQPMessage $message): void
    {
        if ($result === 'ack' || $result === true || $result === null) {
            $message->ack();
        } elseif ($result === 'nack') {
            $message->nack(false, false);
        } elseif ($result === 'reject') {
            $message->reject(false);
        } elseif ($result === 'requeue') {
            $message->nack(false, true);
        } else {
            // Default: acknowledge the message
            $message->ack();
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger && ($this->config['logging']['enabled'] ?? false)) {
            $this->logger->log($level, "[MirabelRabbitMQ:Consumer] {$message}", $context);
        }
    }
}
