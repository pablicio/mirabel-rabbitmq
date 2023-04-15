<?php

namespace Pablicio\MirabelRabbitmq;

trait RabbitMQWorkersError
{
  public function errorSettings($channel, $deadLetterExchangeError, $queue, $errorQueue)
  {
    if (defined('self::retry_options')) {
      // Pega as mesmas configurações de retry pra simplificar.
      $error_options = self::retry_options;

      // Error exchange
      $channel->exchange_declare(
        $deadLetterExchangeError,
        $this->hasCustomConfig($error_options, 'retry_exchange_type', config('mirabel_rabbitmq.connections.rabbitmq-php.exchange_type')),
        $this->hasCustomConfig($error_options, 'retry_exchange_passive', false),
        $this->hasCustomConfig($error_options, 'retry_exchange_durable', true),
        $this->hasCustomConfig($error_options, 'retry_exchange_auto_delete', true),
        $this->hasCustomConfig($error_options, 'retry_exchange_internal', false),
        $this->hasCustomConfig($error_options, 'retry_exchange_no_wait', false),
        $this->hasCustomConfig($error_options, 'retry_exchange_arguments', []),
        $this->hasCustomConfig($error_options, 'retry_exchange_ticket', null)
      );

      // Error Queue Settings
      $channel->queue_declare(
        $errorQueue,
        $this->hasCustomConfig($error_options, 'retry_queue_passive', false),
        $this->hasCustomConfig($error_options, 'retry_queue_durable', true),
        $this->hasCustomConfig($error_options, 'retry_queue_exclusive', true),
        $this->hasCustomConfig($error_options, 'retry_queue_auto_delete', false),
        $this->hasCustomConfig($error_options, 'retry_queue_nowait', false),
        new \PhpAmqpLib\Wire\AMQPTable([
          'x-dead-letter-exchange' => $this->hasCustomConfig($error_options, 'x-dead-letter-exchange', ''),
          'x-dead-letter-routing-key' => $this->hasCustomConfig($error_options, 'x-dead-letter-routing-key', $queue)
        ])
      );

      // Error Queue Binding
      $channel->queue_bind($errorQueue, $deadLetterExchangeError);
    };
  }
}
