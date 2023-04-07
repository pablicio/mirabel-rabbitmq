<?php

namespace Pablicio\MirabelRabbitmq;

use Illuminate\Support\ServiceProvider;

class MirabelRabbitmqServiceProvider extends ServiceProvider
{
  public function boot()
  {
    $this->publishes([
      __DIR__ . '/../config/mirabel_rabbitmq.php' => config_path('mirabel_rabbitmq.php'),
    ], 'config');
  }

  public function register()
  {
    $this->mergeConfigFrom(__DIR__ . '/../config/mirabel_rabbitmq.php', 'mirabel_rabbitmq');
  }
}
