<?php

namespace Pablicio\MirabelRabbitmq;

trait RabbitMQHelpers
{
  public function hasCustomConfig($config, $key, $defaultConfig)
  {
    return isset($config[$key]) ? $config[$key] : $defaultConfig;
  }

  public function ack($msg)
  {
    $msg->ack();
    return 'ack';
  }

  public function nack($msg)
  {
    $msg->nack();
    return 'nack';
  }

  public function reject($msg)
  {
    $msg->reject();
    return 'reject';
  }
}
