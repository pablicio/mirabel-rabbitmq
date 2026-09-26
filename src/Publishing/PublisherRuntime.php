<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Publishing;

use Mirabel\RabbitMQ\Connection\ConnectionFactoryInterface;
use Mirabel\RabbitMQ\Connection\QuietClose;
use Mirabel\RabbitMQ\ConnectionConfig;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Keeps one connection and channel per broker/exchange for the lifetime of the
 * process, so long-running publishers (commands, workers that also publish)
 * do not pay a TCP + AMQP handshake per message.
 *
 * Enabled with MB_RABBITMQ_REUSE_CONNECTION=true. A failed publish drops the
 * session; the next attempt opens a fresh one.
 */
final class PublisherRuntime
{
    /** @var array<string, array{connection: object, channel: object}> */
    private static array $sessions = [];

    public static function isEnabled(): bool
    {
        return in_array(strtolower((string) getenv('MB_RABBITMQ_REUSE_CONNECTION')), ['1', 'true', 'yes', 'on'], true);
    }

    public static function publish(
        ConnectionFactoryInterface $factory,
        ConnectionConfig $config,
        AMQPMessage $message,
        string $routingKey,
    ): void {
        $key = implode(':', [
            $factory::class,
            $config->host,
            $config->port,
            $config->user,
            $config->vhost,
            $config->exchange,
            $config->exchangeType,
            $config->publisherConfirms ? 'confirm' : 'plain',
        ]);

        try {
            $channel = self::channel($key, $factory, $config);
            ChannelPublisher::publish($channel, $config, $message, $config->exchange, $routingKey);
        } catch (\Throwable $exception) {
            self::close($key);
            throw $exception;
        }
    }

    public static function closeAll(): void
    {
        foreach (array_keys(self::$sessions) as $key) {
            self::close($key);
        }
    }

    private static function channel(string $key, ConnectionFactoryInterface $factory, ConnectionConfig $config): object
    {
        if (isset(self::$sessions[$key])) {
            return self::$sessions[$key]['channel'];
        }

        $connection = $factory->connect($config);
        try {
            $channel = $connection->channel();
            ChannelPublisher::prepare($channel, $config, $config->exchange);
        } catch (\Throwable $exception) {
            QuietClose::all($connection);
            throw $exception;
        }

        self::$sessions[$key] = ['connection' => $connection, 'channel' => $channel];

        return $channel;
    }

    private static function close(string $key): void
    {
        if (!isset(self::$sessions[$key])) {
            return;
        }

        $session = self::$sessions[$key];
        unset(self::$sessions[$key]);

        QuietClose::all($session['channel'], $session['connection']);
    }
}
