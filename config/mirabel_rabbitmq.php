<?php

return [
  'connections' => [
    'rabbitmq-php' => [
      'host' => env('MB_RABBITMQ_HOST', '192.168.33.12'),
      'port' => env('MB_RABBITMQ_PORT', 5672),
      'user' => env('MB_RABBITMQ_USER', 'guest'),
      'password' => env('MB_RABBITMQ_PASSWORD', 'guest'),
      'exchange' => env('MB_RABBITMQ_EXCHANGE', 'my-exchange'),
      'exchange_type' => env('MB_RABBITMQ_EXCHANGE_TYPE', 'direct')
    ],
  ]
];
