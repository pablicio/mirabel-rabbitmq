<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Laravel;

use Illuminate\Console\Command;
use Mirabel\RabbitMQ\ConnectionConfig;
use Mirabel\RabbitMQ\Mirabel;
use Mirabel\RabbitMQ\Outbox\AmqpOutboxPublisher;
use Mirabel\RabbitMQ\Outbox\OutboxDispatcher;
use Mirabel\RabbitMQ\Outbox\OutboxStoreInterface;

/**
 * php artisan mirabel:outbox
 *
 * Publishes what the outbox holds, every second while there is work, and
 * backs off — 1, 2, 4... up to 30 seconds — while publishing fails.
 */
final class OutboxCommand extends Command
{
    protected $signature = 'mirabel:outbox {--limit=100 : messages per round} {--once : one round, then exit}';

    protected $description = 'Publish the pending messages of the outbox';

    private bool $stop = false;

    public function handle(OutboxStoreInterface $store): int
    {
        $dispatcher = new OutboxDispatcher(
            $store,
            new AmqpOutboxPublisher(ConnectionConfig::fromEnvironment()),
            Mirabel::logger(),
        );
        $this->trap([SIGINT, SIGTERM], fn () => $this->stop = true);
        if (!$this->option('once')) {
            $this->info('Publishing the outbox, Ctrl+C to stop');
        }

        $wait = 1;
        while (!$this->stop) {
            $published = $dispatcher->dispatch((int) $this->option('limit'));
            $failed = $dispatcher->lastFailures();
            $time = date('H:i:s');
            if ($published > 0) {
                $this->line("{$time} published: {$published}");
            }
            if ($failed > 0) {
                $this->line("{$time} failed: {$failed}, {$dispatcher->lastError()?->getMessage()}");
            }
            if ($this->option('once')) {
                break;
            }

            $wait = $failed > 0 ? min($wait * 2, 30) : 1;
            for ($slept = 0; $slept < $wait && !$this->stop; $slept++) {
                sleep(1);
            }
        }

        return self::SUCCESS;
    }
}
