<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq\Facades;

use Illuminate\Support\Facades\Facade;
use Pablicio\MirabelRabbitmq\Contracts\PublisherInterface;
use Pablicio\MirabelRabbitmq\Contracts\ConsumerInterface;
use Pablicio\MirabelRabbitmq\Contracts\ConnectionManagerInterface;

/**
 * @method static PublisherInterface publisher()
 * @method static ConsumerInterface consumer()
 * @method static ConnectionManagerInterface connections()
 * @method static bool publish(string $routingKey, mixed $payload, array $properties = [])
 * @method static void consume(string $queue, \Closure $callback, array $options = [])
 *
 * @see \Pablicio\MirabelRabbitmq\RabbitMQManager
 */
class RabbitMQ extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'rabbitmq';
    }
}
