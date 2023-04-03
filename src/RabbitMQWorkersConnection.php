<?php

namespace Pablicio\MirabelRabbitmq;

use PhpAmqpLib\Connection\AMQPStreamConnection;

trait RabbitMQWorkersConnection
{
  private $queue;
  private $routingKeys;

  public function consume()
  {
    // Config Connection
    $connection = new AMQPStreamConnection(
      config('mirabel_rabbitmq.connections.rabbitmq-php.host'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.port'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.user'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.password')
    );

    $channel = $connection->channel();

    // Declare Exchange
    $channel->exchange_declare(
      config('mirabel_rabbitmq.connections.rabbitmq-php.exchange'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.exchange_type')
    );

    // Declare Queue
    $channel->queue_declare(
      $this->queue,
      false,
      true,
      false,
      false
    );

    // Subscribe in all routing keys
    foreach ($this->routingKeys as $routing) {
      $channel->queue_bind(
        $this->queue,
        config('mirabel_rabbitmq.connections.rabbitmq-php.exchange'),
        $routing
      );
    }

    // Define callback function
    $callback = function ($msg) {
      $this->work($msg->body, $msg);
    };

    // Define consumer
    $channel->basic_consume(
      $this->queue,
      '',
      false,
      true,
      false,
      false,
      $callback
    );

    while ($channel->is_consuming()) {
      $channel->wait();
    }

    // Close Connection
    $channel->close();
    $connection->close();
  }
}
