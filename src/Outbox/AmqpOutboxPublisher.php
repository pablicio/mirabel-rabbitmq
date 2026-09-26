<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Outbox;

use Mirabel\RabbitMQ\Connection\ConnectionFactoryInterface;
use Mirabel\RabbitMQ\Connection\PhpAmqpConnectionFactory;
use Mirabel\RabbitMQ\Connection\QuietClose;
use Mirabel\RabbitMQ\ConnectionConfig;
use Mirabel\RabbitMQ\Publishing\ChannelPublisher;
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
            ChannelPublisher::prepare($channel, $this->config, $message->exchange);

            $properties = $message->properties + [
                'message_id' => $message->id,
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ];
            ChannelPublisher::publish(
                $channel,
                $this->config,
                new AMQPMessage($message->body, $properties),
                $message->exchange,
                $message->routingKey,
            );
        } finally {
            QuietClose::all($channel, $connection);
        }
    }
}
