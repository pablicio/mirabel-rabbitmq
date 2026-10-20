<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Exception;

use RuntimeException;

/**
 * ask() waited and nobody answered. The question may still be in a queue,
 * to be answered later — to nobody.
 */
final class NoReplyException extends RuntimeException
{
}
