<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Idempotency;

use PDO;
use PDOException;

/**
 * Keys already processed, in a database table any number of workers share.
 * Works with SQLite, MySQL/MariaDB and PostgreSQL; the table is created on
 * first use.
 *
 * It answers "was this key processed?" before the handler and records the
 * key after it succeeds. Two workers handling the same key at the same time
 * both see "no": to rule that out, reserve the business effect itself (see
 * the docs).
 */
final class PdoIdempotencyStore implements IdempotencyStoreInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'mirabel_processed_keys',
    ) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name \"{$table}\".");
        }

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} ("
            . 'idempotency_key VARCHAR(191) NOT NULL PRIMARY KEY, '
            . 'processed_at VARCHAR(32) NOT NULL)',
        );
    }

    public function has(string $key): bool
    {
        $query = $this->pdo->prepare("SELECT 1 FROM {$this->table} WHERE idempotency_key = ?");
        $query->execute([$key]);

        return $query->fetchColumn() !== false;
    }

    public function remember(string $key): void
    {
        try {
            $this->pdo->prepare("INSERT INTO {$this->table} VALUES (?, ?)")
                ->execute([$key, gmdate('Y-m-d\TH:i:s\Z')]);
        } catch (PDOException $exception) {
            // 23000 / 23505: the key is already there, which is the point.
            if (!in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }
        }
    }
}
