<?php

return [
  'connections' => [
    'rabbitmq-php' => [
      'host' => env('RABBITMQ_HOST', '192.168.33.12'),
      'port' => env('RABBITMQ_PORT', 5672),
      'user' => env('RABBITMQ_USER', 'guest'),
      'password' => env('RABBITMQ_PASSWORD', 'guest'),
      'exchange' => env('RABBITMQ_EXCHANGE', 'my-exchange'),
      'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', false),
      'exchange_passive' => env('RABBITMQ_EXCHANGE_PASSIVE', false),
      'exchange_durable' => env('RABBITMQ_EXCHANGE_DURABLE', true),
      'exchange_auto_delete' => env('RABBITMQ_EXCHANGE_DELETE', false),
      'exchange_nowait' => env('RABBITMQ_EXCHANGE_NOWAIT', false),
      'exchange_arguments' => env('RABBITMQ_EXCHANGE_ARGUMENTS', []),
      'exchange_ticket' => env('RABBITMQ_EXCHANGE_TICKET', null)
    ],
  ]
];
