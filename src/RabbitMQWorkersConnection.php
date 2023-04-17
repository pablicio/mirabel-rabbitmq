<?php

namespace Pablicio\MirabelRabbitmq;

use PhpAmqpLib\Connection\AMQPStreamConnection;

trait RabbitMQWorkersConnection
{
  use RabbitMQWorkersNormal;
  use RabbitMQWorkersRetry;
  use RabbitMQWorkersError;
  use RabbitMQHelpers;

  public function subscribe()
  {
    ########################################################################################
    ######################################## Basic Config ##################################
    ########################################################################################
    $options = defined('self::options') ? self::options : [];
    $queue             = defined('self::QUEUE') ? self::QUEUE : '';
    $routingKeys       = defined('self::routing_keys') && count(self::routing_keys) ? self::routing_keys : [];
    $retryQueue        = $queue . '.retry';
    $errorQueue        = $queue . '.error';
    $max_retry_counter = -1;

    ########################################################################################
    ################################## Config Connection  ##################################
    ########################################################################################
    // Config Connection
    $connection = new AMQPStreamConnection(
      config('mirabel_rabbitmq.connections.rabbitmq-php.host'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.port'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.user'),
      config('mirabel_rabbitmq.connections.rabbitmq-php.password')
    );

    // Set exchanges and queues configs
    $exchange                = $queue;
    $deadLetterExchangeRetry = $retryQueue;
    $deadLetterExchangeError = $errorQueue;
    $channel = $connection->channel();

    ########################################################################################
    ################################ General Exchange Setting  #############################
    ########################################################################################
    // General exchange
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

    ########################################################################################
    ################################ Normal Exchange Setting  ##############################
    ########################################################################################
    $this->normalSettings(
      $channel,
      $exchange,
      $queue,
      $retryQueue
    );

    ########################################################################################
    ################################ Retry Exchange Setting  ###############################
    ########################################################################################
    $retry_options = $this->retrySettings(
      $channel,
      $deadLetterExchangeRetry,
      $queue,
      $retryQueue
    );

    ########################################################################################
    ################################ Error Exchange Setting  ###############################
    ########################################################################################
    $this->errorSettings(
      $channel,
      $deadLetterExchangeError,
      $queue,
      $errorQueue
    );

    ########################################################################################
    ################################## Subscriber Setting  #################################
    ########################################################################################
    foreach ($routingKeys as $routing) {
      // bind in new generated exchange
      $channel->queue_bind(
        $queue,
        $exchange,
        $routing
      );

      // bind in general exchange
      $channel->queue_bind(
        $queue,
        config('mirabel_rabbitmq.connections.rabbitmq-php.exchange'),
        $routing
      );
    }

    ########################################################################################
    #################################### Callback Setting  #################################
    ########################################################################################
    if (defined('self::retry_options')) {
      $callback = function ($msg) use ($retry_options, $channel, $deadLetterExchangeError, &$max_retry_counter) {
        if ($max_retry_counter >= $retry_options['max-attempts']) {
          $channel->basic_publish(
            $msg,
            $deadLetterExchangeError
          );
          $msg->ack();
        } else {
          if ($this->work($msg) === 'ack' && !$max_retry_counter) {
            $max_retry_counter = -1;
          }
        }
      };
    } else {
      $callback = function ($msg) {
        $this->work($msg);
      };
    }

    ########################################################################################
    #################################### Consumer Setting  #################################
    ########################################################################################
    // Defines how many messages will be taken from the queue at a time
    $channel->basic_qos(
      $this->hasCustomConfig($options, 'qos_prefetch_size', null), 
      $this->hasCustomConfig($options, 'qos_prefetch_count', 1), 
      $this->hasCustomConfig($options, 'qos_a_global', null)
    );

    // Defines the basic Consumer
    $channel->basic_consume(
      $queue,
      $this->hasCustomConfig($options, 'consume_consumer_tag', ''), 
      $this->hasCustomConfig($options, 'consume_no_local', false), 
      $this->hasCustomConfig($options, 'consume_no_ack', false), 
      $this->hasCustomConfig($options, 'consume_exclusive', false), 
      $this->hasCustomConfig($options, 'consume_nowait', false), 
      $callback,
      $this->hasCustomConfig($options, 'consume_ticket', false), 
    );

    ########################################################################################
    ############################### Retry Counter Setting  #################################
    ########################################################################################
    while (count($channel->callbacks)) {
      // Set max retry by execution
      if (defined('self::retry_options')) {
        if ($max_retry_counter >= $retry_options['max-attempts']) {
          $max_retry_counter = -1;
        }
        $max_retry_counter++;
      }

      $channel->wait();
    }

    // Close connection
    $channel->close();
    $connection->close();
  }
}
