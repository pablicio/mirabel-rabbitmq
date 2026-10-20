<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Laravel;

use Illuminate\Support\ServiceProvider;
use Mirabel\RabbitMQ\Mirabel;
use Mirabel\RabbitMQ\Observability\MinimumLevelLogger;
use Mirabel\RabbitMQ\Outbox\OutboxStoreInterface;
use Mirabel\RabbitMQ\Outbox\PdoOutboxStore;

/**
 * Optional Laravel bridge, found by package discovery. The core does not
 * depend on Laravel: this class is only loaded inside a Laravel application.
 */
final class MirabelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/mirabel.php', 'mirabel');

        // The outbox lives in the application's database, on the same PDO the
        // application writes with, so add() joins its DB::transaction().
        $this->app->singleton(OutboxStoreInterface::class, fn ($app) => new PdoOutboxStore(
            $app['db']->connection()->getPdo(),
        ));
    }

    public function boot(): void
    {
        $config = (array) $this->app['config']->get('mirabel', []);
        Environment::export($config);

        Mirabel::useLogger(new MinimumLevelLogger(
            $this->app['log']->channel($config['log_channel'] ?? null),
            (string) ($config['log_level'] ?? 'info'),
        ));

        if ($this->app->runningInConsole()) {
            $this->commands([WorkCommand::class, DeclareCommand::class, OutboxCommand::class]);
            $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
            $this->publishes([
                __DIR__ . '/../../config/mirabel.php' => $this->app->configPath('mirabel.php'),
            ], 'mirabel-config');
        }
    }
}
