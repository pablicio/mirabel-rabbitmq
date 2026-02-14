<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq\Queue;

use PhpAmqpLib\Wire\AMQPTable;
use Pablicio\MirabelRabbitmq\Connection\ChannelPool;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class DeadLetterQueueManager
{
    private ChannelPool $channelPool;
    private LoggerInterface $logger;

    public function __construct(
        ChannelPool $channelPool,
        ?LoggerInterface $logger = null
    ) {
        $this->channelPool = $channelPool;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Cria uma fila com dead-letter exchange configurado
     */
    public function createQueueWithDLX(
        string $queueName,
        array $options = []
    ): array {
        $channel = $this->channelPool->getChannel('dlq_manager');

        // Nomes dos componentes DLQ
        $dlxName = $options['dlx_name'] ?? "{$queueName}.dlx";
        $dlqName = $options['dlq_name'] ?? "{$queueName}.dlq";
        $dlxRoutingKey = $options['dlx_routing_key'] ?? $queueName;

        try {
            // Cria o Dead Letter Exchange
            $channel->exchange_declare(
                $dlxName,
                $options['dlx_type'] ?? 'direct',
                false,
                true,  // durable
                false
            );

            $this->logger->info('Dead Letter Exchange created', [
                'exchange' => $dlxName,
            ]);

            // Cria a Dead Letter Queue
            $dlqArguments = new AMQPTable([
                'x-queue-type' => $options['dlq_type'] ?? 'classic',
            ]);

            if (isset($options['dlq_message_ttl'])) {
                $dlqArguments->set('x-message-ttl', $options['dlq_message_ttl']);
            }

            if (isset($options['dlq_max_length'])) {
                $dlqArguments->set('x-max-length', $options['dlq_max_length']);
            }

            $channel->queue_declare(
                $dlqName,
                false,
                true,
                false,
                false,
                false,
                $dlqArguments
            );

            $channel->queue_bind($dlqName, $dlxName, $dlxRoutingKey);

            // Cria a fila principal com argumentos para DLX
            $queueArguments = new AMQPTable([
                'x-dead-letter-exchange' => $dlxName,
                'x-dead-letter-routing-key' => $dlxRoutingKey,
            ]);

            if (isset($options['message_ttl'])) {
                $queueArguments->set('x-message-ttl', $options['message_ttl']);
            }

            $channel->queue_declare(
                $queueName,
                false,
                $options['durable'] ?? true,
                false,
                false,
                false,
                $queueArguments
            );

            return [
                'queue' => $queueName,
                'dlx' => $dlxName,
                'dlq' => $dlqName,
                'routing_key' => $dlxRoutingKey,
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to create queue with DLX', [
                'queue' => $queueName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
