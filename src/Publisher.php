<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Pablicio\MirabelRabbitmq\Contracts\PublisherInterface;
use Pablicio\MirabelRabbitmq\Contracts\ConnectionManagerInterface;
use Pablicio\MirabelRabbitmq\Contracts\SerializerInterface;
use Pablicio\MirabelRabbitmq\Exceptions\RabbitMQException;
use Psr\Log\LoggerInterface;

class Publisher implements PublisherInterface
{
    private ?string $exchange = null;
    private ?string $connectionName = null;

    public function __construct(
        private readonly ConnectionManagerInterface $connectionManager,
        private readonly SerializerInterface $serializer,
        private readonly array $config,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function publish(string $routingKey, mixed $payload, array $properties = []): bool
    {
        try {
            $connection = $this->connectionName ?? $this->config['default'];
            $channel = $this->connectionManager->channel($connection);
            $exchange = $this->exchange ?? $this->getDefaultExchange($connection);

            // Declare exchange if needed
            $this->declareExchange($connection, $exchange);

            // Serialize payload
            $body = $this->serializer->serialize($payload);

            // Build message properties
            $messageProperties = $this->buildMessageProperties($properties);

            // Create AMQP message
            $message = new AMQPMessage($body, $messageProperties);

            // Publish message
            $channel->basic_publish($message, $exchange, $routingKey);

            $this->log('info', "Message published to [{$exchange}] with routing key [{$routingKey}]", [
                'exchange' => $exchange,
                'routing_key' => $routingKey,
                'payload_size' => strlen($body),
            ]);

            return true;
        } catch (\Exception $e) {
            $this->log('error', "Failed to publish message: {$e->getMessage()}", [
                'routing_key' => $routingKey,
                'exception' => get_class($e),
            ]);
            
            throw RabbitMQException::publishFailed($routingKey, $e->getMessage());
        } finally {
            // Reset for next publish
            $this->exchange = null;
            $this->connectionName = null;
        }
    }

    public function toExchange(string $exchange): self
    {
        $this->exchange = $exchange;
        return $this;
    }

    public function connection(string $connection): self
    {
        $this->connectionName = $connection;
        return $this;
    }

    private function declareExchange(string $connection, string $exchange): void
    {
        $channel = $this->connectionManager->channel($connection);
        $exchangeConfig = $this->getExchangeConfig($connection);

        $channel->exchange_declare(
            $exchange,
            $exchangeConfig['type'],
            $exchangeConfig['passive'],
            $exchangeConfig['durable'],
            $exchangeConfig['auto_delete'],
            $exchangeConfig['internal'],
            $exchangeConfig['nowait'],
            new AMQPTable($exchangeConfig['arguments'] ?? [])
        );
    }

    private function buildMessageProperties(array $customProperties): array
    {
        $defaultProperties = [
            'content_type' => $this->serializer->getContentType(),
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'timestamp' => time(),
            'message_id' => $this->generateMessageId(),
        ];

        // Merge custom properties with defaults
        $properties = array_merge($defaultProperties, $customProperties);

        // Convert headers to AMQPTable if present
        if (isset($properties['application_headers']) && is_array($properties['application_headers'])) {
            $properties['application_headers'] = new AMQPTable($properties['application_headers']);
        }

        return $properties;
    }

    private function generateMessageId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    private function getDefaultExchange(string $connection): string
    {
        return $this->config['connections'][$connection]['exchange']['name'] ?? 'default';
    }

    private function getExchangeConfig(string $connection): array
    {
        return $this->config['connections'][$connection]['exchange'] ?? [];
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger && ($this->config['logging']['enabled'] ?? false)) {
            $this->logger->log($level, "[MirabelRabbitMQ:Publisher] {$message}", $context);
        }
    }
}
