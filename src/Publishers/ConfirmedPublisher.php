<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq\Publishers;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Pablicio\MirabelRabbitmq\Connection\ChannelPool;
use Pablicio\MirabelRabbitmq\Contracts\SerializerInterface;
use Pablicio\MirabelRabbitmq\Exceptions\RabbitMQException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ConfirmedPublisher
{
    private ChannelPool $channelPool;
    private SerializerInterface $serializer;
    private LoggerInterface $logger;
    private array $pendingConfirms = [];
    private int $nextPublishSeqNo = 1;
    private bool $confirmMode = false;

    public function __construct(
        ChannelPool $channelPool,
        SerializerInterface $serializer,
        ?LoggerInterface $logger = null
    ) {
        $this->channelPool = $channelPool;
        $this->serializer = $serializer;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Publica uma mensagem com confirmação
     */
    public function publish(
        mixed $message,
        string $exchange,
        string $routingKey = '',
        array $options = []
    ): bool {
        $channel = $this->channelPool->getChannel('publisher');

        // Ativa modo de confirmação se necessário
        if (!$this->confirmMode) {
            $this->enableConfirmMode($channel);
        }

        try {
            // Serializa a mensagem
            $body = $this->serializer->serialize($message);

            // Prepara propriedades da mensagem
            $properties = $this->prepareMessageProperties($options);

            // Cria mensagem AMQP
            $amqpMessage = new AMQPMessage($body, $properties);

            // Armazena info para confirmação
            $deliveryTag = $this->nextPublishSeqNo++;
            $this->pendingConfirms[$deliveryTag] = [
                'exchange' => $exchange,
                'routing_key' => $routingKey,
                'message' => $message,
                'timestamp' => microtime(true),
            ];

            // Publica a mensagem
            $channel->basic_publish($amqpMessage, $exchange, $routingKey);

            // Aguarda confirmação
            $confirmed = $this->waitForConfirm($channel, $deliveryTag, $options['timeout'] ?? 5);

            if ($confirmed) {
                $this->logger->info('Message published and confirmed', [
                    'exchange' => $exchange,
                    'routing_key' => $routingKey,
                    'delivery_tag' => $deliveryTag,
                ]);
            } else {
                $this->logger->warning('Message published but not confirmed', [
                    'exchange' => $exchange,
                    'routing_key' => $routingKey,
                    'delivery_tag' => $deliveryTag,
                ]);
            }

            return $confirmed;

        } catch (\Exception $e) {
            $this->logger->error('Failed to publish message', [
                'exchange' => $exchange,
                'routing_key' => $routingKey,
                'error' => $e->getMessage(),
            ]);

            throw RabbitMQException::publishFailed($routingKey, $e->getMessage());
        }
    }

    /**
     * Publica múltiplas mensagens em lote
     */
    public function publishBatch(
        array $messages,
        string $exchange,
        ?callable $routingKeyResolver = null,
        array $options = []
    ): array {
        $channel = $this->channelPool->getChannel('batch_publisher');

        if (!$this->confirmMode) {
            $this->enableConfirmMode($channel);
        }

        $results = [];
        $startDeliveryTag = $this->nextPublishSeqNo;

        try {
            foreach ($messages as $index => $message) {
                $routingKey = $routingKeyResolver 
                    ? $routingKeyResolver($message, $index)
                    : '';

                $body = $this->serializer->serialize($message);
                $properties = $this->prepareMessageProperties($options);
                $amqpMessage = new AMQPMessage($body, $properties);

                $deliveryTag = $this->nextPublishSeqNo++;
                $this->pendingConfirms[$deliveryTag] = [
                    'exchange' => $exchange,
                    'routing_key' => $routingKey,
                    'message' => $message,
                    'timestamp' => microtime(true),
                    'batch_index' => $index,
                ];

                $channel->basic_publish($amqpMessage, $exchange, $routingKey);
            }

            // Aguarda confirmação de todas as mensagens
            $endDeliveryTag = $this->nextPublishSeqNo - 1;
            $results = $this->waitForBatchConfirms(
                $channel,
                $startDeliveryTag,
                $endDeliveryTag,
                $options['timeout'] ?? 30
            );

            $this->logger->info('Batch published', [
                'total_messages' => count($messages),
                'confirmed' => count(array_filter($results)),
                'failed' => count(array_filter($results, fn($r) => !$r)),
            ]);

            return $results;

        } catch (\Exception $e) {
            $this->logger->error('Failed to publish batch', [
                'exchange' => $exchange,
                'error' => $e->getMessage(),
            ]);

            throw RabbitMQException::publishFailed($exchange, $e->getMessage());
        }
    }

    /**
     * Ativa modo de confirmação no canal
     */
    private function enableConfirmMode(AMQPChannel $channel): void
    {
        $channel->confirm_select();
        
        // Configura callbacks de confirmação
        $channel->set_ack_handler(function ($message) {
            $this->handleAck($message->getDeliveryTag());
        });

        $channel->set_nack_handler(function ($message) {
            $this->handleNack($message->getDeliveryTag());
        });

        $this->confirmMode = true;
        $this->logger->info('Publisher confirm mode enabled');
    }

    /**
     * Aguarda confirmação de uma mensagem
     */
    private function waitForConfirm(AMQPChannel $channel, int $deliveryTag, int $timeout): bool
    {
        $startTime = time();

        while (isset($this->pendingConfirms[$deliveryTag])) {
            if (time() - $startTime > $timeout) {
                $this->logger->warning('Confirm timeout', ['delivery_tag' => $deliveryTag]);
                return false;
            }

            $channel->wait(null, false, $timeout);
        }

        return true;
    }

    /**
     * Aguarda confirmação de lote de mensagens
     */
    private function waitForBatchConfirms(
        AMQPChannel $channel,
        int $startTag,
        int $endTag,
        int $timeout
    ): array {
        $results = [];
        $startTime = time();

        for ($tag = $startTag; $tag <= $endTag; $tag++) {
            $results[$tag] = false;
        }

        while (!empty($this->pendingConfirms)) {
            if (time() - $startTime > $timeout) {
                $this->logger->warning('Batch confirm timeout', [
                    'pending' => count($this->pendingConfirms),
                ]);
                break;
            }

            try {
                $channel->wait(null, false, 1);
            } catch (\Exception $e) {
                $this->logger->error('Error waiting for confirms', [
                    'error' => $e->getMessage(),
                ]);
                break;
            }

            // Marca mensagens confirmadas
            foreach ($results as $tag => $confirmed) {
                if (!isset($this->pendingConfirms[$tag])) {
                    $results[$tag] = true;
                }
            }
        }

        return $results;
    }

    /**
     * Handler para ACK (confirmação positiva)
     */
    private function handleAck(int $deliveryTag): void
    {
        if (isset($this->pendingConfirms[$deliveryTag])) {
            $this->logger->debug('Message confirmed', ['delivery_tag' => $deliveryTag]);
            unset($this->pendingConfirms[$deliveryTag]);
        }
    }

    /**
     * Handler para NACK (confirmação negativa)
     */
    private function handleNack(int $deliveryTag): void
    {
        if (isset($this->pendingConfirms[$deliveryTag])) {
            $this->logger->warning('Message nacked', [
                'delivery_tag' => $deliveryTag,
                'message' => $this->pendingConfirms[$deliveryTag],
            ]);
            unset($this->pendingConfirms[$deliveryTag]);
        }
    }

    /**
     * Prepara propriedades da mensagem
     */
    private function prepareMessageProperties(array $options): array
    {
        $properties = [
            'content_type' => $this->serializer->getContentType(),
            'delivery_mode' => $options['persistent'] ?? true ? AMQPMessage::DELIVERY_MODE_PERSISTENT : AMQPMessage::DELIVERY_MODE_NON_PERSISTENT,
            'timestamp' => time(),
        ];

        // Adiciona headers customizados
        if (isset($options['headers'])) {
            $properties['application_headers'] = new AMQPTable($options['headers']);
        }

        // Adiciona TTL se especificado
        if (isset($options['expiration'])) {
            $properties['expiration'] = (string) $options['expiration'];
        }

        // Adiciona prioridade se especificada
        if (isset($options['priority'])) {
            $properties['priority'] = $options['priority'];
        }

        // Adiciona message ID se especificado
        if (isset($options['message_id'])) {
            $properties['message_id'] = $options['message_id'];
        }

        // Adiciona correlation ID se especificado
        if (isset($options['correlation_id'])) {
            $properties['correlation_id'] = $options['correlation_id'];
        }

        return $properties;
    }

    /**
     * Retorna estatísticas do publisher
     */
    public function getStats(): array
    {
        return [
            'pending_confirms' => count($this->pendingConfirms),
            'next_seq_no' => $this->nextPublishSeqNo,
            'confirm_mode' => $this->confirmMode,
        ];
    }
}
