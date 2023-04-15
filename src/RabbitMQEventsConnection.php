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
    // Config Connection
    $connection = new AMQPStreamConnection(
      config('mirabel_rabbitmq.connections.rabbitmq-php.host'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.port'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.user'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.password')
    );

    $channel = $connection->channel();

    $channel->exchange_declare(
      config('mirabel_rabbitmq.connections.rabbitmq-php.exchange'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.exchange_type'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.exchange_passive'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.exchange_durable'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.exchange_auto_delete'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.exchange_nowait'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.exchange_arguments'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.exchange_ticket'),
    );

    // Message Body
    $msg = new AMQPMessage($this->payload);

    // Basic Publish
    $channel->basic_publish(
      $msg,
      config('mirabel_rabbitmq.connections.rabbitmq-php.exchange'),
      $this->routingKey
    );

    // Close Connection
    $channel->close();
    $connection->close();
  }
}
