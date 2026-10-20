<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Tests;

use Mirabel\RabbitMQ\Outbox\OutboxMessage;
use Mirabel\RabbitMQ\Outbox\PdoOutboxStore;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoOutboxStoreTest extends TestCase
{
    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }
    }

    public function testKeepsMessagesUntilTheyArePublished(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $store = new PdoOutboxStore($pdo);
        $store->install();
        $store->install();

        $pdo->beginTransaction();
        $store->add(new OutboxMessage('pedido-1-pago', 'doce.eventos', 'pedido.pago', '{"pedido":1}', ['delivery_mode' => 2]));
        $store->add(new OutboxMessage('pedido-2-pago', 'doce.eventos', 'pedido.pago', '{"pedido":2}'));
        $pdo->commit();

        self::assertSame(2, $store->countPending());
        $pending = iterator_to_array($store->pending(10), false);
        self::assertSame(['pedido-1-pago', 'pedido-2-pago'], array_map(static fn (OutboxMessage $m) => $m->id, $pending));
        self::assertSame(['delivery_mode' => 2], $pending[0]->properties);

        $store->markFailed('pedido-1-pago', new \RuntimeException('Cannot reach RabbitMQ'));
        $store->markPublished('pedido-2-pago');

        self::assertSame(1, $store->countPending());
        $row = $pdo->query("SELECT failures, last_error FROM mirabel_outbox WHERE id = 'pedido-1-pago'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['failures' => 1, 'last_error' => 'Cannot reach RabbitMQ'], $row);
    }

    public function testARolledBackTransactionLeavesNothingToPublish(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $store = new PdoOutboxStore($pdo);
        $store->install();

        $pdo->beginTransaction();
        $store->add(new OutboxMessage('pedido-3-pago', 'doce.eventos', 'pedido.pago', '{}'));
        $pdo->rollBack();

        self::assertSame(0, $store->countPending());
    }
}
