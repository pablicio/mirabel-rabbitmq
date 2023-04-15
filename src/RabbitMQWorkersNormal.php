<?php

namespace Pablicio\MirabelRabbitmq;

trait RabbitMQWorkersNormal
{
  public function normalSettings($channel, $exchange, $queue, $retryQueue)
  {
    // Normal Options
    $queue_options = count(self::options) ? self::options : [];

    // Normal exchange
    $channel->exchange_declare(
      $exchange,
      $this->hasCustomConfig($queue_options, 'exchange_type' ,config('mirabel_rabbitmq.connections.rabbitmq-php.exchange_type')),
      $this->hasCustomConfig($queue_options, 'exchange_passive', false),
      $this->hasCustomConfig($queue_options, 'exchange_durable', true),
      $this->hasCustomConfig($queue_options, 'exchange_auto_delete', true),
      $this->hasCustomConfig($queue_options, 'exchange_internal', false),
      $this->hasCustomConfig($queue_options, 'exchange_no_wait', false),
      $this->hasCustomConfig($queue_options, 'exchange_arguments', []),
      $this->hasCustomConfig($queue_options, 'exchange_ticket', null)
    );

    // Normal Queue Settings
    $channel->queue_declare(
      $queue, 
      $this->hasCustomConfig($queue_options, 'queue_passive', false),
      $this->hasCustomConfig($queue_options, 'queue_durable', true),
      $this->hasCustomConfig($queue_options, 'queue_exclusive', true),
      $this->hasCustomConfig($queue_options, 'queue_auto_delete', false),
      $this->hasCustomConfig($queue_options, 'queue_nowait', false),
      new \PhpAmqpLib\Wire\AMQPTable([
      'x-dead-letter-exchange' => $this->hasCustomConfig($queue_options, 'x-dead-letter-exchange', ''),
      'x-dead-letter-routing-key' => $this->hasCustomConfig($queue_options, 'x-dead-letter-routing-key', $retryQueue)
    ]));

    // Normal Queue Binding
    $channel->queue_bind($queue, $exchange);
  }
}
