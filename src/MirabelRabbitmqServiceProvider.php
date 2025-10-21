<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Support\DeferrableProvider;
use Pablicio\MirabelRabbitmq\Contracts\ConnectionManagerInterface;
use Pablicio\MirabelRabbitmq\Contracts\PublisherInterface;
use Pablicio\MirabelRabbitmq\Contracts\ConsumerInterface;
use Pablicio\MirabelRabbitmq\Contracts\SerializerInterface;
use Pablicio\MirabelRabbitmq\Serializers\JsonSerializer;
use Pablicio\MirabelRabbitmq\Serializers\PhpSerializer;
use Psr\Log\LoggerInterface;

class MirabelRabbitmqServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/mirabel_rabbitmq.php',
            'mirabel_rabbitmq'
        );

        // Register Serializer
        $this->app->singleton(SerializerInterface::class, function ($app) {
            $serializer = config('mirabel_rabbitmq.serializer', 'json');

            return match ($serializer) {
                'php', 'serialize' => new PhpSerializer(),
                default => new JsonSerializer(),
            };
        });

        // Register Connection Manager
        $this->app->singleton(ConnectionManagerInterface::class, function ($app) {
            return new ConnectionManager(
                config('mirabel_rabbitmq'),
                $this->getLogger($app)
            );
        });

        // Register Publisher
        $this->app->bind(PublisherInterface::class, function ($app) {
            return new Publisher(
                $app->make(ConnectionManagerInterface::class),
                $app->make(SerializerInterface::class),
                config('mirabel_rabbitmq'),
                $this->getLogger($app)
            );
        });

        // Register Consumer
        $this->app->bind(ConsumerInterface::class, function ($app) {
            return new Consumer(
                $app->make(ConnectionManagerInterface::class),
                $app->make(SerializerInterface::class),
                config('mirabel_rabbitmq'),
                $this->getLogger($app)
            );
        });

        // Register RabbitMQ Manager (Facade accessor)
        $this->app->singleton('rabbitmq', function ($app) {
            return new RabbitMQManager(
                $app->make(ConnectionManagerInterface::class),
                $app->make(PublisherInterface::class),
                $app->make(ConsumerInterface::class)
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/mirabel_rabbitmq.php' => config_path('mirabel_rabbitmq.php'),
            ], 'mirabel-rabbitmq-config');

            $this->commands([
                Console\ConsumeCommand::class,
                Console\PublishCommand::class,
            ]);
        }
    }

    public function provides(): array
    {
        return [
            ConnectionManagerInterface::class,
            PublisherInterface::class,
            ConsumerInterface::class,
            SerializerInterface::class,
            'rabbitmq',
        ];
    }

    private function getLogger($app): ?LoggerInterface
    {
        if (!config('mirabel_rabbitmq.logging.enabled', true)) {
            return null;
        }

        $channel = config('mirabel_rabbitmq.logging.channel', 'stack');

        try {
            return $app->make('log')->channel($channel);
        } catch (\Exception) {
            return null;
        }
    }
}
