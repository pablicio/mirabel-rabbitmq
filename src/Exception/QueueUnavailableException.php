<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Exception;

use RuntimeException;

/**
 * The queue exists but the broker cannot serve it right now — a classic
 * queue whose node is down, for instance. Its messages are not lost; they
 * are out of reach until the node comes back.
 */
final class QueueUnavailableException extends RuntimeException
{
}
