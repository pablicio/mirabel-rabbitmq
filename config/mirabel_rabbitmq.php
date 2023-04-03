<?php

return [
  'connections' => [
    'rabbitmq-php' => [
      'host' => env('RABBITMQ_HOST', '192.168.33.12'),
      'port' => env('RABBITMQ_PORT', 5672),
      'user' => env('RABBITMQ_USER', 'guest'),
      'password' => env('RABBITMQ_PASSWORD', 'guest'),
      'exchange' => env('RABBITMQ_EXCHANGE', 'self-waiter'),
      'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'direct')
    ],
  ]
];
