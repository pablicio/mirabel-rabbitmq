<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Outbox;

use Mirabel\RabbitMQ\Connection\ConnectionFactoryInterface;
use Mirabel\RabbitMQ\Connection\PhpAmqpConnectionFactory;
use Mirabel\RabbitMQ\ConnectionConfig;
use PhpAmqpLib\Message\AMQPMessage;

final class AmqpOutboxPublisher implements OutboxPublisherInterface
{
    public function __construct(
        private readonly ConnectionConfig $config,
        private readonly ConnectionFactoryInterface $connectionFactory = new PhpAmqpConnectionFactory(),
    ) {
    }

    public function publish(OutboxMessage $message): void
    {
        $connection = $this->connectionFactory->connect($this->config);
        $channel = null;

        try {
            $channel = $connection->channel();
            $channel->exchange_declare($message->exchange, $this->config->exchangeType, false, true, false);
            if ($this->config->publisherConfirms) {
                $channel->confirm_select();
            }

            $properties = $message->properties + [
                'message_id' => $message->id,
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ];
            $channel->basic_publish(
                new AMQPMessage($message->body, $properties),
                $message->exchange,
                $message->routingKey,
            );

            if ($this->config->publisherConfirms) {
                $channel->wait_for_pending_acks();
            }
        } finally {
            if ($channel !== null) {
                $channel->close();
            }
            $connection->close();
        }
    }
}
