<?php

namespace Pablicio\MirabelRabbitmq;

use PhpAmqpLib\Connection\AMQPStreamConnection;

trait RabbitMQWorkersConnection
{
  public function subscribe()
  {
    /* #####################################################################################
    ######################################## Basic Config ##################################
    ##################################################################################### */
    $queue             = defined('self::QUEUE') ? self::QUEUE : '';
    $routingKeys       = defined('self::routing_keys') && count(self::routing_keys) ? self::routing_keys : [];
    $retryQueue        = $queue . '.retry';
    $errorQueue        = $queue . '.error';
    $max_retry_counter = -1;

    /* #####################################################################################
    ################################## Config Connection  ##################################
    ##################################################################################### */
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

    /* #####################################################################################
    ################################ General Exchange Setting  #############################
    ##################################################################################### */

    // General Options
    $generalExchange = config('mirabel_rabbitmq.connections.rabbitmq-php.exchange');
    $generalType = config('mirabel_rabbitmq.connections.rabbitmq-php.exchange_type');
    $generalPassive = false;
    $generalDurable = true;
    $generalAutoDelete = true;
    $generalInternal = false;
    $generalNowait = false;
    $generalArguments = [];
    $generalTicket = null;

    // General exchange
    $channel->exchange_declare(
      $generalExchange,
      $generalType,
      $generalPassive,
      $generalDurable,
      $generalAutoDelete,
      $generalInternal,
      $generalNowait,
      $generalArguments,
      $generalTicket
    );

    /* #####################################################################################
    ################################ Normal Exchange Setting  ##############################
    ##################################################################################### */

    // Normal Options
    $queue_options = count(self::options) ? self::options : [];

    $normalType = $this->hasCustomConfig(
      $queue_options,
      'type',
      config('mirabel_rabbitmq.connections.rabbitmq-php.exchange_type')
    );
    $normalPassive =  $this->hasCustomConfig($queue_options, 'passive' , false);
    $normalDurable = $this->hasCustomConfig($queue_options, 'durable' , true);
    $normalAutoDelete = $this->hasCustomConfig($queue_options, 'auto_delete' , true);
    $normalInternal = $this->hasCustomConfig($queue_options, 'internal' , false);
    $normalNowait = $this->hasCustomConfig($queue_options, 'no_wait' , false);
    $normalArguments = $this->hasCustomConfig($queue_options, 'arguments' , []);
    $normalTicket = $this->hasCustomConfig($queue_options, 'ticket' , null);

    // Normal exchange
    $channel->exchange_declare(
      $exchange,
      $normalType,
      $normalPassive,
      $normalDurable,
      $normalAutoDelete,
      $normalInternal,
      $normalNowait,
      $normalArguments,
      $normalTicket
    );

    // Normal Queue Settings
    $channel->queue_declare($queue, false, true, false, false, false, new \PhpAmqpLib\Wire\AMQPTable([
      'x-dead-letter-exchange' => $this->hasCustomConfig($queue_options, 'x-dead-letter-exchange', ''),
      'x-dead-letter-routing-key' => $this->hasCustomConfig($queue_options, 'x-dead-letter-routing-key', $retryQueue)
    ]));
    $channel->queue_bind($queue, $exchange);


    /* #####################################################################################
    ################################ Retry Exchange Setting  ###############################
    ##################################################################################### */

    // Retry Options
    if (defined('self::retry_options')) {
      $retry_options = self::retry_options;

      if (isset($retry_options['active']) && $retry_options['active']) {
        $retryType =  $this->hasCustomConfig(
          $retry_options,
          'type',
          config('mirabel_rabbitmq.connections.rabbitmq-php.exchange_type')
        );
        $retryPassive =  $this->hasCustomConfig($retry_options, 'passive', false);
        $retryDurable = $this->hasCustomConfig($retry_options, 'durable', true);
        $retryAutoDelete = $this->hasCustomConfig($retry_options, 'auto_delete', true);
        $retryInternal = $this->hasCustomConfig($retry_options, 'internal', false);
        $retryNowait = $this->hasCustomConfig($retry_options, 'no_wait', false);
        $retryArguments = $this->hasCustomConfig($retry_options, 'arguments', []);
        $retryTicket = $this->hasCustomConfig($retry_options, 'ticket', null);

        // Retry exchange
        $channel->exchange_declare(
          $deadLetterExchangeRetry,
          $retryType,
          $retryPassive,
          $retryDurable,
          $retryAutoDelete,
          $retryInternal,
          $retryNowait,
          $retryArguments,
          $retryTicket
        );

        // Retry Queue Settings
        $channel->queue_declare($retryQueue, false, true, false, false, false, new \PhpAmqpLib\Wire\AMQPTable([
          'x-dead-letter-exchange' => $this->hasCustomConfig($retry_options, 'x-dead-letter-exchange', ''),
          'x-dead-letter-routing-key' => $this->hasCustomConfig($retry_options, 'x-dead-letter-routing-key', $queue),
          'x-message-ttl' => $this->hasCustomConfig($retry_options, 'x-message-ttl', 0)
        ]));
        $channel->queue_bind($retryQueue, $deadLetterExchangeRetry);
      }
    }

    /* #####################################################################################
    ################################ Error Exchange Setting  ###############################
    ##################################################################################### */
    if (defined('self::error_options')) {
      $error_options = self::error_options;

      if (isset($error_options['active']) && $error_options['active']) {
        // Error Options
        $errorType =  $this->hasCustomConfig($error_options, 'type', config('mirabel_rabbitmq.connections.rabbitmq-php.exchange_type'));
        $errorPassive =  $this->hasCustomConfig($error_options, 'passive', false);
        $errorDurable = $this->hasCustomConfig($error_options, 'durable', true);
        $errorAutoDelete = $this->hasCustomConfig($error_options, 'auto_delete', true);
        $errorInternal = $this->hasCustomConfig($error_options, 'internal', false);
        $errorNowait = $this->hasCustomConfig($error_options, 'no_wait', false);
        $errorArguments = $this->hasCustomConfig($error_options, 'arguments', []);
        $errorTicket = $this->hasCustomConfig($error_options, 'ticket', null);

        // Error exchange
        $channel->exchange_declare(
          $deadLetterExchangeError,
          $errorType,
          $errorPassive,
          $errorDurable,
          $errorAutoDelete,
          $errorInternal,
          $errorNowait,
          $errorArguments,
          $errorTicket
        );

        // Error Queue Settings
        $channel->queue_declare($errorQueue, false, true, false, false, false, new \PhpAmqpLib\Wire\AMQPTable([
          'x-dead-letter-exchange' => $this->hasCustomConfig($error_options, 'x-dead-letter-exchange', ''),
          'x-dead-letter-routing-key' => $this->hasCustomConfig($error_options, 'x-dead-letter-routing-key', $queue)
        ]));
        $channel->queue_bind($errorQueue, $deadLetterExchangeError);
      }
    };

    /* #####################################################################################
    ################################## Subscriber Setting  #################################
    ##################################################################################### */

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

    /* #####################################################################################
    #################################### Callback Setting  #################################
    ##################################################################################### */

    if (defined('self::error_options') && defined('self::retry_options') && isset($retry_options['active']) && $retry_options['active']) {
      $callback = function ($msg) use ($retry_options, $channel, $deadLetterExchangeError, &$max_retry_counter) {
        if ($max_retry_counter >= $retry_options['max_attempts']) {
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

    /* #####################################################################################
    #################################### Consumer Setting  #################################
    ##################################################################################### */

    // Defines how many messages will be taken from the queue at a time
    $channel->basic_qos(null, 1, null);

    // Defines the basic Consumer
    $channel->basic_consume($queue, '', false, false, false, false, $callback);

    /* #####################################################################################
    ############################### Retry Counter Setting  #################################
    ##################################################################################### */

    while (count($channel->callbacks)) {
      // Set max retry by execution
      if (defined('self::retry_options') && isset($retry_options['active']) && $retry_options['active']) {
        if ($max_retry_counter >= $retry_options['max_attempts']) {
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

  private function hasCustomConfig($config, $key, $defaultConfig)
  {
    return isset($config[$key]) ? $config[$key] : $defaultConfig;
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

  private function reject($msg)
  {
    $msg->reject();
    return 'reject';
  }
}
