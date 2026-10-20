<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Exception;

use Mirabel\RabbitMQ\ConnectionConfig;
use RuntimeException;

/**
 * The broker could not be reached: nothing listening on the port, wrong
 * host, refused credentials, a timeout while opening the connection. The
 * transport error is not chained: its reason is already in the message, and
 * PHP would print the inner exception first.
 */
final class ConnectionException extends RuntimeException
{
    public static function to(ConnectionConfig $config, \Throwable $previous): self
    {
        $reason = trim(preg_replace('/\s+/', ' ', $previous->getMessage()) ?? '');
        // "stream_socket_client(): Unable to connect to tcp://h:p (Connection refused)"
        if (preg_match('/Unable to connect to \S+ \((.+)\)$/', $reason, $match)) {
            $reason = $match[1];
        }

        return new self(
            sprintf('Cannot reach RabbitMQ at %s:%d: %s', $config->host, $config->port, $reason),
            (int) $previous->getCode(),
        );
    }
}
