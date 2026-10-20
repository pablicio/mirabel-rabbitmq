<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Process-wide defaults. Events and workers log here unless they override
 * logger(); a framework bridge plugs its logger in once, at boot.
 */
final class Mirabel
{
    private static ?LoggerInterface $logger = null;

    public static function useLogger(?LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    public static function logger(): LoggerInterface
    {
        return self::$logger ??= new NullLogger();
    }
}
