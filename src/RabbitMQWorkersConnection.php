<?php

namespace Pablicio\MirabelRabbitmq;

use PhpAmqpLib\Connection\AMQPStreamConnection;

trait RabbitMQWorkersConnection
{
  private $queue;
  private $routingKeys;

  public function consume()
  {
    $connection = new AMQPStreamConnection(
      config('queue.connections.rabbitmq-php.host'),
      config('queue.connections.rabbitmq-php.port'),
      config('queue.connections.rabbitmq-php.user'),
      config('queue.connections.rabbitmq-php.password')
    );

    $channel = $connection->channel();

    $channel->exchange_declare(
      config('queue.connections.rabbitmq-php.exchange'),
      config('queue.connections.rabbitmq-php.exchange_type')
    );

    $channel->queue_declare(
      $this->queue,
      false,
      true,
      false,
      false
    );

    foreach ($this->routingKeys as $routing) {
      $channel->queue_bind(
        $this->queue,
        config('queue.connections.rabbitmq-php.exchange'),
        $routing
      );
    }

    echo "[*] Waiting for messages. To exit press CTRL+C\n";

    $callback = function ($msg) {
      $this->work($msg->body, $msg);
    };

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
    $channel->close();
    $connection->close();
  }
}
