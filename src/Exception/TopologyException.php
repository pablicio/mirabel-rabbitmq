<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Exception;

use RuntimeException;

/**
 * The broker refused the worker's topology (a queue that exists with other
 * arguments or another type, missing permissions). Reconnecting cannot fix it,
 * so the worker stops instead of retrying forever.
 */
final class TopologyException extends RuntimeException
{
}
