<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq\Console;

use Illuminate\Console\Command;
use Pablicio\MirabelRabbitmq\Facades\RabbitMQ;

class ConsumeCommand extends Command
{
    protected $signature = 'rabbitmq:consume 
                            {queue : The queue to consume from}
                            {handler : The handler class (must have handle method)}
                            {--connection= : The connection to use}
                            {--keys=* : Routing keys to bind}
                            {--retry : Enable retry mechanism}
                            {--max-attempts=3 : Maximum retry attempts}';

    protected $description = 'Consume messages from a RabbitMQ queue';

    public function handle(): int
    {
        $queue = $this->argument('queue');
        $handlerClass = $this->argument('handler');
        $connection = $this->option('connection');
        $routingKeys = $this->option('keys');
        $retry = $this->option('retry');
        $maxAttempts = (int) $this->option('max-attempts');

        // Validate handler class
        if (!class_exists($handlerClass)) {
            $this->error("Handler class [{$handlerClass}] not found");
            return self::FAILURE;
        }

        $handler = app($handlerClass);

        if (!method_exists($handler, 'handle')) {
            $this->error("Handler class must have a handle() method");
            return self::FAILURE;
        }

        $this->info("Starting consumer for queue [{$queue}]");

        $consumer = RabbitMQ::consumer();

        if ($connection) {
            $consumer->connection($connection);
        }

        if (!empty($routingKeys)) {
            $consumer->bindKeys($routingKeys);
            $this->info("Bound routing keys: " . implode(', ', $routingKeys));
        }

        if ($retry) {
            $consumer->withRetry($maxAttempts);
            $this->info("Retry enabled with max {$maxAttempts} attempts");
        }

        // Handle graceful shutdown
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $consumer->stop());
        pcntl_signal(SIGINT, fn() => $consumer->stop());

        try {
            $consumer->consume($queue, function ($payload, $message) use ($handler) {
                return $handler->handle($payload, $message);
            });
        } catch (\Exception $e) {
            $this->error("Consumer error: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->info("Consumer stopped");
        return self::SUCCESS;
    }
}
