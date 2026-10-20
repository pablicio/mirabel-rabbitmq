<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Outbox;

use PDO;

/**
 * The outbox as a table of the application's own database (SQLite,
 * MySQL/MariaDB, PostgreSQL). add() must run on the same PDO, inside the same
 * transaction, as the business change; OutboxDispatcher publishes what is
 * pending.
 *
 * install() creates the table. Run it once, outside any transaction — in a
 * migration or a deploy step: MySQL commits an open transaction on DDL.
 */
final class PdoOutboxStore implements OutboxStoreInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'mirabel_outbox',
    ) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name \"{$table}\".");
        }
    }

    public function install(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} ("
            . 'id VARCHAR(191) NOT NULL PRIMARY KEY, '
            . 'message TEXT NOT NULL, '
            . 'created_at VARCHAR(32) NOT NULL, '
            . 'published_at VARCHAR(32) NULL, '
            . 'failures INTEGER NOT NULL DEFAULT 0, '
            . 'last_error TEXT NULL)',
        );
    }

    public function add(OutboxMessage $message): void
    {
        $this->pdo->prepare("INSERT INTO {$this->table} (id, message, created_at) VALUES (?, ?, ?)")
            ->execute([$message->id, json_encode($message->toArray(), JSON_THROW_ON_ERROR), self::now()]);
    }

    public function pending(int $limit): iterable
    {
        $rows = $this->pdo->query(
            "SELECT message FROM {$this->table} WHERE published_at IS NULL "
            . 'ORDER BY created_at, id LIMIT ' . max(1, $limit),
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($rows as $row) {
            yield OutboxMessage::fromArray(json_decode((string) $row, true, flags: JSON_THROW_ON_ERROR));
        }
    }

    public function markPublished(string $id): void
    {
        $this->pdo->prepare("UPDATE {$this->table} SET published_at = ? WHERE id = ?")
            ->execute([self::now(), $id]);
    }

    public function markFailed(string $id, \Throwable $exception): void
    {
        $this->pdo->prepare("UPDATE {$this->table} SET failures = failures + 1, last_error = ? WHERE id = ?")
            ->execute([substr($exception->getMessage(), 0, 1000), $id]);
    }

    /** Messages not published yet. */
    public function countPending(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->table} WHERE published_at IS NULL")->fetchColumn();
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
