<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Tests;

use Mirabel\RabbitMQ\Idempotency\PdoIdempotencyStore;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoIdempotencyStoreTest extends TestCase
{
    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }
    }

    public function testRemembersKeysAndIgnoresTheSecondRecord(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $store = new PdoIdempotencyStore($pdo);

        self::assertFalse($store->has('pedido-40117'));
        $store->remember('pedido-40117');
        $store->remember('pedido-40117');

        self::assertTrue($store->has('pedido-40117'));
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM mirabel_processed_keys')->fetchColumn());
    }

    public function testRefusesATableNameThatIsNotAnIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PdoIdempotencyStore(new PDO('sqlite::memory:'), 'keys; DROP TABLE x');
    }
}
