<?php

namespace Pablicio\MirabelRabbitmq;

trait RabbitMQWorkersRetry
{
  public function retrySettings($channel, $deadLetterExchangeRetry, $queue, $retryQueue)
  {
    // Retry Options
    if (defined('self::retry_options')) {
      $retry_options = self::retry_options;

      // Retry exchange
      $channel->exchange_declare(
        $deadLetterExchangeRetry,
        $this->hasCustomConfig($retry_options, 'retry_exchange_type', config('mirabel_rabbitmq.connections.rabbitmq-php.exchange_type')),
        $this->hasCustomConfig($retry_options, 'retry_exchange_passive', false),
        $this->hasCustomConfig($retry_options, 'retry_exchange_durable', true),
        $this->hasCustomConfig($retry_options, 'retry_exchange_auto_delete', false),
        $this->hasCustomConfig($retry_options, 'retry_exchange_internal', false),
        $this->hasCustomConfig($retry_options, 'retry_exchange_no_wait', false),
        $this->hasCustomConfig($retry_options, 'retry_exchange_arguments', []),
        $this->hasCustomConfig($retry_options, 'retry_exchange_ticket', null)
      );

      // Retry Queue Settings
      $channel->queue_declare(
        $retryQueue,
        $this->hasCustomConfig($retry_options, 'retry_queue_passive', false),
        $this->hasCustomConfig($retry_options, 'retry_queue_durable', true),
        $this->hasCustomConfig($retry_options, 'retry_queue_exclusive', false),
        $this->hasCustomConfig($retry_options, 'retry_queue_auto_delete', false),
        $this->hasCustomConfig($retry_options, 'retry_queue_nowait', false),
        new \PhpAmqpLib\Wire\AMQPTable([
          'x-dead-letter-exchange' => $this->hasCustomConfig($retry_options, 'x-dead-letter-exchange', ''),
          'x-dead-letter-routing-key' => $this->hasCustomConfig($retry_options, 'x-dead-letter-routing-key', $queue),
          'x-message-ttl' => $this->hasCustomConfig($retry_options, 'x-message-ttl', 0)
        ])
      );

      // Retry Queue Binding
      $channel->queue_bind($retryQueue, $deadLetterExchangeRetry);
    }

    return $retry_options;
  }
}
