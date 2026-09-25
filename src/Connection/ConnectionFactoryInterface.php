<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Connection;

use Mirabel\RabbitMQ\ConnectionConfig;
use PhpAmqpLib\Connection\AbstractConnection;

interface ConnectionFactoryInterface
{
    public function connect(ConnectionConfig $config): AbstractConnection;
}