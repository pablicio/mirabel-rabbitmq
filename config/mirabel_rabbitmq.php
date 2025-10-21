<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Connection
    |--------------------------------------------------------------------------
    |
    | The default RabbitMQ connection to use when none is specified.
    |
    */
    'default' => env('RABBITMQ_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | RabbitMQ Connections
    |--------------------------------------------------------------------------
    |
    | Configure multiple RabbitMQ connections for different environments
    | or services. Each connection can have its own credentials and settings.
    |
    */
    'connections' => [
        'default' => [
            'host' => env('RABBITMQ_HOST', 'localhost'),
            'port' => env('RABBITMQ_PORT', 5672),
            'user' => env('RABBITMQ_USER', 'guest'),
            'password' => env('RABBITMQ_PASSWORD', 'guest'),
            'vhost' => env('RABBITMQ_VHOST', '/'),
            
            // Connection options
            'connection_timeout' => env('RABBITMQ_CONNECTION_TIMEOUT', 3),
            'read_write_timeout' => env('RABBITMQ_READ_WRITE_TIMEOUT', 3),
            'heartbeat' => env('RABBITMQ_HEARTBEAT', 0),
            'keepalive' => env('RABBITMQ_KEEPALIVE', false),
            
            // SSL options
            'ssl' => env('RABBITMQ_SSL', false),
            'ssl_options' => [],
            
            // Exchange configuration
            'exchange' => [
                'name' => env('RABBITMQ_EXCHANGE', 'default'),
                'type' => env('RABBITMQ_EXCHANGE_TYPE', 'topic'),
                'passive' => env('RABBITMQ_EXCHANGE_PASSIVE', false),
                'durable' => env('RABBITMQ_EXCHANGE_DURABLE', true),
                'auto_delete' => env('RABBITMQ_EXCHANGE_AUTO_DELETE', false),
                'internal' => env('RABBITMQ_EXCHANGE_INTERNAL', false),
                'nowait' => env('RABBITMQ_EXCHANGE_NOWAIT', false),
                'arguments' => [],
            ],
            
            // Queue defaults
            'queue' => [
                'passive' => false,
                'durable' => true,
                'exclusive' => false,
                'auto_delete' => false,
                'nowait' => false,
            ],
            
            // QoS settings
            'qos' => [
                'prefetch_size' => 0,
                'prefetch_count' => 1,
                'global' => false,
            ],
            
            // Consumer settings
            'consumer' => [
                'tag' => '',
                'no_local' => false,
                'no_ack' => false,
                'exclusive' => false,
                'nowait' => false,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configure retry behavior for failed messages. When enabled, failed
    | messages will be retried with exponential backoff.
    |
    */
    'retry' => [
        'enabled' => env('RABBITMQ_RETRY_ENABLED', true),
        'max_attempts' => env('RABBITMQ_RETRY_MAX_ATTEMPTS', 3),
        'initial_delay' => env('RABBITMQ_RETRY_INITIAL_DELAY', 1000), // milliseconds
        'multiplier' => env('RABBITMQ_RETRY_MULTIPLIER', 2),
        'max_delay' => env('RABBITMQ_RETRY_MAX_DELAY', 30000), // milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Dead Letter Configuration
    |--------------------------------------------------------------------------
    |
    | Configure dead letter exchange for messages that exceed retry attempts
    | or are explicitly rejected.
    |
    */
    'dead_letter' => [
        'enabled' => env('RABBITMQ_DLX_ENABLED', true),
        'suffix' => env('RABBITMQ_DLX_SUFFIX', '.dlx'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Enable logging for RabbitMQ operations. Useful for debugging and
    | monitoring message flow.
    |
    */
    'logging' => [
        'enabled' => env('RABBITMQ_LOGGING_ENABLED', true),
        'channel' => env('RABBITMQ_LOG_CHANNEL', 'stack'),
        'level' => env('RABBITMQ_LOG_LEVEL', 'info'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Serialization
    |--------------------------------------------------------------------------
    |
    | Choose how messages are serialized. Options: json, serialize, msgpack
    |
    */
    'serializer' => env('RABBITMQ_SERIALIZER', 'json'),
];
