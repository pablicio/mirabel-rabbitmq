<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Publishing;

use Mirabel\RabbitMQ\ConnectionConfig;
use Mirabel\RabbitMQ\Exception\PublishNotConfirmedException;
use Mirabel\RabbitMQ\Exception\UnroutableException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * The single publishing routine shared by events, the reusable publisher
 * session and the outbox publisher.
 */
final class ChannelPublisher
{
    /**
     * Declares the exchange and, when enabled, puts the channel in confirm mode.
     * Must run once per channel, before the first publish. Mandatory
     * publishing needs confirm mode, to know when the broker is done.
     */
    public static function prepare(object $channel, ConnectionConfig $config, string $exchange, ?string $exchangeType = null, bool $mandatory = false): void
    {
        $channel->exchange_declare($exchange, $exchangeType ?? $config->exchangeType, false, true, false);

        if ($config->publisherConfirms || $mandatory) {
            $channel->confirm_select();
        }
    }

    /**
     * Publishes one message. With publisher confirms enabled it waits for the
     * broker's answer and throws when the broker rejects (nacks) the message or
     * does not answer within the read/write timeout.
     */
    public static function publish(object $channel, ConnectionConfig $config, AMQPMessage $message, string $exchange, string $routingKey, bool $mandatory = false): void
    {
        if (!$config->publisherConfirms && !$mandatory) {
            $channel->basic_publish($message, $exchange, $routingKey);

            return;
        }

        $nacked = false;
        $channel->set_nack_handler(static function () use (&$nacked): void {
            $nacked = true;
        });

        if (!$mandatory) {
            $channel->basic_publish($message, $exchange, $routingKey);
            $channel->wait_for_pending_acks($config->readWriteTimeout);
        } else {
            $returned = null;
            $channel->set_return_listener(static function (int $code, string $reason) use (&$returned): void {
                $returned = [$code, $reason];
            });
            $channel->basic_publish($message, $exchange, $routingKey, true);
            $channel->wait_for_pending_acks_returns($config->readWriteTimeout);
            if ($returned !== null) {
                throw UnroutableException::returned($returned[0], $returned[1], $exchange, $routingKey);
            }
        }

        if ($nacked) {
            throw PublishNotConfirmedException::forRoutingKey($exchange, $routingKey);
        }
    }
}
