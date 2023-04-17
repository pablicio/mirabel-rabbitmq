<?php

return [
  'connections' => [
    'rabbitmq-php' => [
      'host' => env('MB_RABBITMQ_HOST', 'localhost'),
      'port' => env('MB_RABBITMQ_PORT', 5672),
      'user' => env('MB_RABBITMQ_USER', 'guest'),
      'password' => env('MB_RABBITMQ_PASSWORD', 'guest'),
      'exchange' => env('MB_RABBITMQ_EXCHANGE', 'my-exchange'),
      'exchange_type' => env('MB_RABBITMQ_EXCHANGE_TYPE', 'topic'),
      'exchange_passive' => env('MB_RABBITMQ_EXCHANGE_PASSIVE', false),
      'exchange_durable' => env('MB_RABBITMQ_EXCHANGE_DURABLE', true),
      'exchange_auto_delete' => env('MB_RABBITMQ_EXCHANGE_DELETE', false),
      'exchange_nowait' => env('MB_RABBITMQ_EXCHANGE_NOWAIT', false),
      'exchange_arguments' => env('MB_RABBITMQ_EXCHANGE_ARGUMENTS', []),
      'exchange_ticket' => env('MB_RABBITMQ_EXCHANGE_TICKET', null)
    ],
  ]
];
