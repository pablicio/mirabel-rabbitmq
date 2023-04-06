<?php

namespace Pablicio\MirabelRabbitmq;

use PhpAmqpLib\Connection\AMQPStreamConnection;

trait RabbitMQWorkersConnection
{
  public function consume()
  {
    $queue             = self::QUEUE;
    $retryQueue        = $queue . '.retry';
    $retryQueue        = $queue . '.retry';
    $errorQueue        = $queue . '.error';
    $routingKeys       = self::routing_keys ?? [];
    $arguments         = self::arguments ?? [];
    $max_retry_counter = -1;

    // Config Connection
    $connection = new AMQPStreamConnection(
      config('mirabel_rabbitmq.connections.rabbitmq-php.host'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.port'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.user'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.password')
    );

    $channel = $connection->channel();

    // Set exchanges and queues configs
    $exchange                = config('mirabel_rabbitmq.connections.rabbitmq-php.exchange');
    $deadLetterExchangeRetry = config('mirabel_rabbitmq.connections.rabbitmq-php.exchange') . '.retry';
    $deadLetterExchangeError = config('mirabel_rabbitmq.connections.rabbitmq-php.exchange') . '.error';

    // Normal exchange
    $channel->exchange_declare(
      $exchange, 
      config('mirabel_rabbitmq.connections.rabbitmq-php.exchange_type'), 
      false, 
      true
    );

    // Retry exchange
    $channel->exchange_declare(
      $deadLetterExchangeRetry, 
      config('mirabel_rabbitmq.connections.rabbitmq-php.exchange_type'), 
      false, 
      true
    );

    // Error exchange
    $channel->exchange_declare(
      $deadLetterExchangeError, 
      config('mirabel_rabbitmq.connections.rabbitmq-php.exchange_type'),
      false,
      true
    );

    // Normal queue
    $channel->queue_declare($queue, false, true, false, false, false, new \PhpAmqpLib\Wire\AMQPTable([
      'x-dead-letter-exchange' => '',
      'x-dead-letter-routing-key' => $retryQueue
    ]));
    $channel->queue_bind($queue, $exchange);

    // Retry queue with TTL
    $channel->queue_declare($retryQueue, false, true, false, false, false, new \PhpAmqpLib\Wire\AMQPTable([
      'x-dead-letter-exchange' => '',
      'x-dead-letter-routing-key' => $queue,
      'x-message-ttl' => $arguments['ttl']
    ]));
    $channel->queue_bind($retryQueue, $deadLetterExchangeRetry);

    // Error queue with TTL
    $channel->queue_declare($errorQueue, false, true, false, false);
    $channel->queue_bind($errorQueue, $deadLetterExchangeError);

    // Subscribe in all routing keys
    foreach ($routingKeys as $routing) {
      $channel->queue_bind(
        $queue,
        config('mirabel_rabbitmq.connections.rabbitmq-php.exchange'),
        $routing
      );
    }

    $callback = function ($msg) use ($arguments, $channel, $deadLetterExchangeError, &$max_retry_counter) {
      if ($max_retry_counter >= $arguments['max_attempts']) {
        $channel->basic_publish(
          $msg,
          $deadLetterExchangeError
        );
        $msg->ack();
      } else {
        if ($this->work($msg->body, $msg) === 'ack') {
          $max_retry_counter = -1;
        }
      }
    };

    // Defines how many messages will be taken from the queue at a time
    $channel->basic_qos(null, 1, null);

    // Defines the basic Consumer
    $channel->basic_consume($queue, '', false, false, false, false, $callback);

    while (count($channel->callbacks)) {
      // Set max retry by execution
      if ($max_retry_counter >= $arguments['max_attempts']) {
        $max_retry_counter = -1;
      }
      $max_retry_counter++;

      $channel->wait();
    }

    // Close connection
    $channel->close();
    $connection->close();
  }

  private function ack($msg)
  {
    $msg->ack();
    return 'ack';
  }

  private function nack($msg)
  {
    $msg->nack();
    return 'nack';
  }
}
