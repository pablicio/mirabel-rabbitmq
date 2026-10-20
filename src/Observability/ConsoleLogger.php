<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Observability;

use Psr\Log\AbstractLogger;

/**
 * One line per message on the terminal — "12:10:03 WARNING RabbitMQ worker
 * connection will retry." — for workers that run as plain PHP scripts, in a
 * container or under a supervisor that keeps their output:
 *
 *     Mirabel::useLogger(new ConsoleLogger());
 *
 * Writes to STDERR by default, where supervisors and `docker logs` expect
 * logs; pair it with MinimumLevelLogger to hide the per-message debug lines.
 */
final class ConsoleLogger extends AbstractLogger
{
    /** @var resource */
    private $stream;

    /** @param resource|null $stream */
    public function __construct($stream = null)
    {
        $this->stream = $stream ?? STDERR;
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        fwrite($this->stream, sprintf("%s %s %s\n", date('H:i:s'), strtoupper((string) $level), $message));
    }
}
