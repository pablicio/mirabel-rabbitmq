<?php

namespace Pablicio\MirabelRabbitmq;

use Illuminate\Support\ServiceProvider;


class MirabelRabbitmqServiceProvider extends ServiceProvider
{
  public function boot()
  {
    $this->publishes([
      __DIR__ . '/../config/rabbitmq_php_support.php' => config_path('rabbitmq_php_support.php'),
    ], 'config');
  }

  public function register()
  {
    $this->mergeConfigFrom(__DIR__.'/../config/rabbitmq_php_support.php', 'rabbitmq_php_support');

  }
}
