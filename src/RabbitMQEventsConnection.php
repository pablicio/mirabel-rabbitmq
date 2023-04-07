<?php namespace Pablicio\MirabelRabbitmq;

require_once(__DIR__ . '/../Support/helpers.php');

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
      mb_config_path('mirabel_rabbitmq.connections.rabbitmq-php.host'),
      mb_config_path('mirabel_rabbitmq.connections.rabbitmq-php.port'),
      mb_config_path('mirabel_rabbitmq.connections.rabbitmq-php.user'),
      mb_config_path('mirabel_rabbitmq.connections.rabbitmq-php.password')
    );

    $channel = $connection->channel();
    $channel->exchange_declare(
      mb_config_path('mirabel_rabbitmq.connections.rabbitmq-php.exchange'),
      mb_config_path('mirabel_rabbitmq.connections.rabbitmq-php.exchange_type'),
      false, 
      true
    );

    // Message Body
    $msg = new AMQPMessage($this->payload);

    // Basic Publish
    $channel->basic_publish(
      $msg,
      mb_config_path('mirabel_rabbitmq.connections.rabbitmq-php.exchange'),
      $this->routingKey
    );

    // Close Connection
    $channel->close();
    $connection->close();
  }
}
