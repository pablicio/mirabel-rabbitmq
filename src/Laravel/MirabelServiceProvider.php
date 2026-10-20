<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Laravel;

use Illuminate\Support\ServiceProvider;
use Mirabel\RabbitMQ\Mirabel;

/**
 * Optional Laravel bridge, found by package discovery. The core does not
 * depend on Laravel: this class is only loaded inside a Laravel application.
 */
final class MirabelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/mirabel.php', 'mirabel');
    }

    public function boot(): void
    {
        $config = (array) $this->app['config']->get('mirabel', []);
        Environment::export($config);

        $channel = $config['log_channel'] ?? null;
        Mirabel::useLogger($this->app['log']->channel($channel));

        if ($this->app->runningInConsole()) {
            $this->commands([WorkCommand::class]);
            $this->publishes([
                __DIR__ . '/../../config/mirabel.php' => $this->app->configPath('mirabel.php'),
            ], 'mirabel-config');
        }
    }
}
