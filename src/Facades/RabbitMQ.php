<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade para acesso simplificado ao RabbitMQ
 * 
 * @method static bool publishEvent(mixed $payload, string $routingKey, ?string $exchange = null, ?string $connection = null, array $options = [])
 * @method static void subscribe(string $queue, callable $callback, array $routingKeys = [], array $options = [], array $retryOptions = [], ?string $connection = null)
 * @method static bool publish(mixed $message, string $routingKey, ?string $exchange = null, array $options = [])
 * @method static array publishBatch(array $messages, ?string $exchange = null, ?callable $routingKeyResolver = null, array $options = [])
 * @method static void consume(string $queue, callable $callback, array $options = [])
 * @method static void declareExchange(string $name, string $type = 'direct', array $options = [])
 * @method static array declareQueue(string $name, array $options = [])
 * @method static void bindQueue(string $queue, string $exchange, string $routingKey = '', array $arguments = [])
 * @method static array createQueueWithDLQ(string $queueName, array $options = [])
 * @method static void setQoS(int $prefetchCount = 1, int $prefetchSize = 0, bool $global = false, ?string $channelId = null)
 * @method static array getStats()
 * 
 * @see \Pablicio\MirabelRabbitmq\RabbitMQManager
 */
class RabbitMQ extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'mirabel.rabbitmq';
    }
}
