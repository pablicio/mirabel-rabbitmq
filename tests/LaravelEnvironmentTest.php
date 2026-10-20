<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Tests;

use Mirabel\RabbitMQ\Laravel\Environment;
use Mirabel\RabbitMQ\Mirabel;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

final class LaravelEnvironmentTest extends TestCase
{
    private const NAMES = [
        'MB_RABBITMQ_EXCHANGE',
        'MB_RABBITMQ_PORT',
        'MB_RABBITMQ_PUBLISHER_CONFIRMS',
        'MB_RABBITMQ_VHOST',
    ];

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::NAMES as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        Mirabel::useLogger(null);
    }

    public function testCachedConfigIsExportedUnderTheLibraryNames(): void
    {
        $exported = Environment::export([
            'exchange' => 'doce.eventos',
            'port' => 5673,
            'publisher_confirms' => true,
            'vhost' => null,
            'log_channel' => 'stack',
        ]);

        self::assertSame(['MB_RABBITMQ_EXCHANGE', 'MB_RABBITMQ_PORT', 'MB_RABBITMQ_PUBLISHER_CONFIRMS'], $exported);
        self::assertSame('doce.eventos', getenv('MB_RABBITMQ_EXCHANGE'));
        self::assertSame('5673', getenv('MB_RABBITMQ_PORT'));
        self::assertSame('true', getenv('MB_RABBITMQ_PUBLISHER_CONFIRMS'));
        self::assertFalse(getenv('MB_RABBITMQ_VHOST'));
    }

    public function testVariableSetByTheServerWins(): void
    {
        putenv('MB_RABBITMQ_EXCHANGE=from-the-server');

        $exported = Environment::export(['exchange' => 'from-the-config']);

        self::assertSame([], $exported);
        self::assertSame('from-the-server', getenv('MB_RABBITMQ_EXCHANGE'));
    }

    public function testDefaultLoggerIsSilentUntilABridgePlugsOneIn(): void
    {
        self::assertInstanceOf(NullLogger::class, Mirabel::logger());

        $logger = new class extends AbstractLogger {
            public function log($level, \Stringable|string $message, array $context = []): void
            {
            }
        };
        Mirabel::useLogger($logger);

        self::assertSame($logger, Mirabel::logger());
    }
}
