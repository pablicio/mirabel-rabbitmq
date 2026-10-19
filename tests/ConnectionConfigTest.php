<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Tests;

use Mirabel\RabbitMQ\ConnectionConfig;
use Mirabel\RabbitMQ\Serialization\JsonSerializer;
use PHPUnit\Framework\TestCase;

final class ConnectionConfigTest extends TestCase
{
    private const VARIABLES = ['HOST', 'PORT', 'PUBLISHER_CONFIRMS'];

    /** @var array<string, string|false> */
    private array $original = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->original[$name] = getenv('MB_RABBITMQ_' . $name);
        }
    }

    protected function tearDown(): void
    {
        // Restore, not unset: the integration suite runs in the same
        // process and needs the broker address it was started with.
        foreach ($this->original as $name => $value) {
            putenv($value === false
                ? 'MB_RABBITMQ_' . $name
                : 'MB_RABBITMQ_' . $name . '=' . $value);
        }
    }

    public function testDefaultsAreReadWithoutLaravel(): void
    {
        putenv('MB_RABBITMQ_HOST=broker');
        putenv('MB_RABBITMQ_PORT=5673');
        putenv('MB_RABBITMQ_PUBLISHER_CONFIRMS=true');

        $config = ConnectionConfig::fromEnvironment();

        self::assertSame('broker', $config->host);
        self::assertSame(5673, $config->port);
        self::assertTrue($config->publisherConfirms);
    }

    public function testJsonSerializerRoundTripsPayload(): void
    {
        $serializer = new JsonSerializer();

        self::assertSame(['id' => 42, 'items' => ['one']], $serializer->decode($serializer->encode([
            'id' => 42,
            'items' => ['one'],
        ])));
    }
}