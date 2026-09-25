<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Tests;

use Mirabel\RabbitMQ\ConnectionConfig;
use Mirabel\RabbitMQ\Serialization\JsonSerializer;
use PHPUnit\Framework\TestCase;

final class ConnectionConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['HOST', 'PORT', 'PUBLISHER_CONFIRMS'] as $name) {
            putenv('MB_RABBITMQ_' . $name);
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