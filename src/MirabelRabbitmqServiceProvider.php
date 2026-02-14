<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Support\DeferrableProvider;
use Psr\Log\LoggerInterface;

class MirabelRabbitmqServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/rabbitmq.php',
            'rabbitmq'
        );

        // Register RabbitMQ Manager (Facade accessor)
        $this->app->singleton('mirabel.rabbitmq', function ($app) {
            return new RabbitMQManager(config('rabbitmq'));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/rabbitmq.php' => config_path('rabbitmq.php'),
            ], 'rabbitmq-config');
        }
    }

    public function provides(): array
    {
        return [
            'mirabel.rabbitmq',
        ];
    }
}
