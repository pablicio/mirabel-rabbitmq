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
      config('mirabel_rabbitmq.connections.rabbitmq-php.host'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.port'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.user'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.password')
    );

    $channel = $connection->channel();
    $channel->exchange_declare(
      config('mirabel_rabbitmq.connections.rabbitmq-php.exchange'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.exchange_type')
    );

    // Message Body
    $msg = new AMQPMessage($this->payload);

    // Basic Publish
    $channel->basic_publish(
      $msg,
      config('mirabel_rabbitmq.connections.rabbitmq-php.exchange'),
      $this->routingKey
    );

    echo "[Order Service] Enviou...!\n";
    $channel->close();
    $connection->close();
  }
}
