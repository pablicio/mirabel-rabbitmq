<?php

namespace Pablicio\MirabelRabbitmq;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

trait RabbitMQEventsConnection
{
  private $routingKey;
  private $payload;

  public function publish()
  {
    $connection = new AMQPStreamConnection(
      config('rabbitmq_php_support.connections.rabbitmq-php.host'),
      config('rabbitmq_php_support.connections.rabbitmq-php.port'),
      config('rabbitmq_php_support.connections.rabbitmq-php.user'),
      config('rabbitmq_php_support.connections.rabbitmq-php.password')
    );

    $channel = $connection->channel();
    $channel->exchange_declare(
      config('rabbitmq_php_support.connections.rabbitmq-php.exchange'),
      config('rabbitmq_php_support.connections.rabbitmq-php.exchange_type')
    );

    // Message Body
    $msg = new AMQPMessage($this->payload);

    // Basic Publish
    $channel->basic_publish(
      $msg,
      config('rabbitmq_php_support.connections.rabbitmq-php.exchange'),
      $this->routingKey
    );

    echo "[Order Service] Enviou...!\n";
    $channel->close();
    $connection->close();
  }
}
