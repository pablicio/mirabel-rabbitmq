<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Publishing;

use Mirabel\RabbitMQ\ConnectionConfig;
use Mirabel\RabbitMQ\Exception\PublishNotConfirmedException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * The single publishing routine shared by events, the reusable publisher
 * session and the outbox publisher.
 */
final class ChannelPublisher
{
    /**
     * Declares the exchange and, when enabled, puts the channel in confirm mode.
     * Must run once per channel, before the first publish.
     */
    public static function prepare(object $channel, ConnectionConfig $config, string $exchange, ?string $exchangeType = null): void
    {
        $channel->exchange_declare($exchange, $exchangeType ?? $config->exchangeType, false, true, false);

        if ($config->publisherConfirms) {
            $channel->confirm_select();
        }
    }

    /**
     * Publishes one message. With publisher confirms enabled it waits for the
     * broker's answer and throws when the broker rejects (nacks) the message or
     * does not answer within the read/write timeout.
     */
    public static function publish(object $channel, ConnectionConfig $config, AMQPMessage $message, string $exchange, string $routingKey): void
    {
        if (!$config->publisherConfirms) {
            $channel->basic_publish($message, $exchange, $routingKey);

            return;
        }

        $nacked = false;
        $channel->set_nack_handler(static function () use (&$nacked): void {
            $nacked = true;
        });

        $channel->basic_publish($message, $exchange, $routingKey);
        $channel->wait_for_pending_acks($config->readWriteTimeout);

        if ($nacked) {
            throw PublishNotConfirmedException::forRoutingKey($exchange, $routingKey);
        }
    }
}
