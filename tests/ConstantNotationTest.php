<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Tests;

use Mirabel\RabbitMQ\Envelope;
use Mirabel\RabbitMQ\Event;
use Mirabel\RabbitMQ\Worker;
use PHPUnit\Framework\TestCase;

/**
 * The constant notation: a service declares only what differs from the
 * convention, in one `const` statement.
 */
final class ConstantNotationTest extends TestCase
{
    public function testEventRoutingKeyComesFromTheConstant(): void
    {
        self::assertSame('payments.captured', PaymentCapturedForTest::routingKey());
    }

    public function testEventWithoutConfigurationDerivesTheRoutingKeyFromItsName(): void
    {
        self::assertSame('pedido.pago', PedidoPago::routingKey());
        self::assertSame('order.received', OrderReceivedEvent::routingKey());
    }

    public function testStaticPropertyStillWorksForEvents(): void
    {
        self::assertSame('legacy.event', LegacyPropertyEventForTest::routingKey());
    }

    public function testWorkerReadsEveryConstant(): void
    {
        self::assertSame([
            'queue' => 'fiscal.pedidos-pagos',
            'routing_keys' => ['pedido.pago', 'pedido.reenviado'],
            'retry' => ['delay' => 5000, 'max_attempts' => 4],
            'options' => [
                'qos_prefetch_count' => 10,
                'queue_type' => 'quorum',
            ],
        ], EmitirNotaFiscalForTest::definition());
    }

    public function testWorkerWithoutConfigurationUsesTheConvention(): void
    {
        self::assertSame([
            'queue' => 'enviar-email',
            'routing_keys' => ['pedido.pago'],
            'retry' => ['delay' => 0, 'max_attempts' => 1],
            'options' => [],
        ], EnviarEmailWorker::definition());
    }

    public function testLegacyLowercaseConstantsStillWork(): void
    {
        self::assertSame([
            'queue' => 'store.orders',
            'routing_keys' => ['store.order.created'],
            'retry' => ['delay' => 3600, 'max_attempts' => 4],
            'options' => ['exchange_type' => 'topic'],
        ], LegacyConstantWorkerForTest::definition());
    }

    public function testStaticPropertiesStillWork(): void
    {
        self::assertSame([
            'queue' => 'billing.orders',
            'routing_keys' => ['orders.received'],
            'retry' => ['delay' => 5000, 'max_attempts' => 4],
            'options' => [],
        ], StaticPropertyWorkerForTest::definition());
    }
}

final class PaymentCapturedForTest extends Event
{
    const ROUTING_KEY = 'payments.captured';
}

final class PedidoPago extends Event
{
}

final class OrderReceivedEvent extends Event
{
}

final class LegacyPropertyEventForTest extends Event
{
    public static string $routingKey = 'legacy.event';
}

final class EmitirNotaFiscalForTest extends Worker
{
    const QUEUE = 'fiscal.pedidos-pagos',
        EVENTS = [PedidoPago::class],
        ROUTING_KEYS = ['pedido.reenviado'],
        ATTEMPTS = 4,
        RETRY_DELAY = 5_000,
        PREFETCH = 10,
        QUEUE_TYPE = 'quorum';

    public function handle(Envelope $envelope): void
    {
    }
}

final class EnviarEmailWorker extends Worker
{
    const EVENTS = [PedidoPago::class];

    public function handle(Envelope $envelope): void
    {
    }
}

final class LegacyConstantWorkerForTest extends Worker
{
    const QUEUE = 'store.orders',
        routing_keys = ['store.order.created'],
        options = ['exchange_type' => 'topic'],
        retry_options = ['x-message-ttl' => 3600, 'max-attempts' => 4];

    public function work($msg)
    {
        return $this->ack($msg);
    }
}

final class StaticPropertyWorkerForTest extends Worker
{
    public static string $queue = 'billing.orders';
    public static array $routingKeys = ['orders.received'];
    public static array $retry = ['delay' => 5000, 'max_attempts' => 4];

    public function handle(Envelope $envelope): void
    {
    }
}
