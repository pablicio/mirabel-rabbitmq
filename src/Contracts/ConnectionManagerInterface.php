<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq\Contracts;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;

interface ConnectionManagerInterface
{
    /**
     * Get a connection by name
     *
     * @param string|null $name Connection name (null for default)
     * @return AbstractConnection
     */
    public function connection(?string $name = null): AbstractConnection;

    /**
     * Get a channel for the specified connection
     *
     * @param string|null $connection Connection name
     * @return AMQPChannel
     */
    public function channel(?string $connection = null): AMQPChannel;

    /**
     * Close a specific connection
     *
     * @param string|null $name Connection name (null for all)
     * @return void
     */
    public function disconnect(?string $name = null): void;

    /**
     * Reconnect a connection
     *
     * @param string|null $name Connection name
     * @return AbstractConnection
     */
    public function reconnect(?string $name = null): AbstractConnection;

    /**
     * Check if a connection is alive
     *
     * @param string|null $name Connection name
     * @return bool
     */
    public function isConnected(?string $name = null): bool;
}
