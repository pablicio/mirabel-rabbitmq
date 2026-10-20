<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Observability;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Passes on only the messages at or above a level. The library logs every
 * publish and every processed message at debug; behind this filter at
 * "info", a worker logs reconnects, retries and the error queue, but not
 * one line per message on the hot path.
 */
final class MinimumLevelLogger extends AbstractLogger
{
    private const RANK = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    private readonly int $minimum;

    public function __construct(private readonly LoggerInterface $logger, string $level)
    {
        $this->minimum = self::rank($level);
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        if (self::rank((string) $level) >= $this->minimum) {
            $this->logger->log($level, $message, $context);
        }
    }

    private static function rank(string $level): int
    {
        return self::RANK[strtolower($level)]
            ?? throw new InvalidArgumentException("Unknown log level \"{$level}\".");
    }
}
