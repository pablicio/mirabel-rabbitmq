<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq\Console;

use Illuminate\Console\Command;
use Pablicio\MirabelRabbitmq\Facades\RabbitMQ;

class PublishCommand extends Command
{
    protected $signature = 'rabbitmq:publish 
                            {routing-key : The routing key}
                            {payload : The message payload (JSON string)}
                            {--connection= : The connection to use}
                            {--exchange= : The exchange to publish to}';

    protected $description = 'Publish a message to RabbitMQ';

    public function handle(): int
    {
        $routingKey = $this->argument('routing-key');
        $payloadJson = $this->argument('payload');
        $connection = $this->option('connection');
        $exchange = $this->option('exchange');

        // Parse payload
        try {
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->error("Invalid JSON payload: {$e->getMessage()}");
            return self::FAILURE;
        }

        $publisher = RabbitMQ::publisher();

        if ($connection) {
            $publisher->connection($connection);
        }

        if ($exchange) {
            $publisher->toExchange($exchange);
        }

        try {
            $publisher->publish($routingKey, $payload);
            $this->info("Message published successfully to [{$routingKey}]");
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to publish message: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
