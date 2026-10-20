<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Connection;

use Mirabel\RabbitMQ\ConnectionConfig;
use Mirabel\RabbitMQ\Exception\ConnectionException;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Connection\AbstractConnection;

final class PhpAmqpConnectionFactory implements ConnectionFactoryInterface
{
    public function connect(ConnectionConfig $config): AbstractConnection
    {
        try {
            return $this->open($config);
        } catch (\Throwable $exception) {
            throw ConnectionException::to($config, $exception);
        }
    }

    private function open(ConnectionConfig $config): AbstractConnection
    {
        return new AMQPStreamConnection(
            $config->host,
            $config->port,
            $config->user,
            $config->password,
            $config->vhost,
            false,
            'AMQPLAIN',
            null,
            'en_US',
            $config->connectTimeout,
            $config->readWriteTimeout,
            null,
            false,
            $config->heartbeat,
        );
    }
}