<?php

return [
    'default' => env('RABBITMQ_CONNECTION', 'default'),

    'connections' => [
        'default' => [
            'host' => env('RABBITMQ_HOST', 'localhost'),
            'port' => env('RABBITMQ_PORT', 5672),
            'user' => env('RABBITMQ_USER', 'guest'),
            'password' => env('RABBITMQ_PASSWORD', 'guest'),
            'vhost' => env('RABBITMQ_VHOST', '/'),
            
            // Configurações de conexão
            'heartbeat' => env('RABBITMQ_HEARTBEAT', 60),
            'connection_timeout' => env('RABBITMQ_CONNECTION_TIMEOUT', 3.0),
            'read_write_timeout' => env('RABBITMQ_READ_WRITE_TIMEOUT', 3.0),
            'keepalive' => env('RABBITMQ_KEEPALIVE', true),
            
            // Reconexão automática
            'max_reconnect_attempts' => env('RABBITMQ_MAX_RECONNECT_ATTEMPTS', 5),
            'reconnect_delay' => env('RABBITMQ_RECONNECT_DELAY', 2),
            
            // Pool de canais
            'max_channels' => env('RABBITMQ_MAX_CHANNELS', 10),
            
            // Exchange padrão
            'default_exchange' => env('RABBITMQ_DEFAULT_EXCHANGE', ''),
        ],

        'production' => [
            'host' => env('RABBITMQ_PROD_HOST', 'rabbitmq.production.com'),
            'port' => env('RABBITMQ_PROD_PORT', 5672),
            'user' => env('RABBITMQ_PROD_USER'),
            'password' => env('RABBITMQ_PROD_PASSWORD'),
            'vhost' => env('RABBITMQ_PROD_VHOST', '/'),
            'heartbeat' => 60,
            'connection_timeout' => 5.0,
            'read_write_timeout' => 5.0,
            'keepalive' => true,
            'max_reconnect_attempts' => 10,
            'reconnect_delay' => 3,
            'max_channels' => 20,
        ],
    ],

    // Configurações de QoS padrão
    'qos' => [
        'prefetch_count' => env('RABBITMQ_PREFETCH_COUNT', 1),
        'prefetch_size' => env('RABBITMQ_PREFETCH_SIZE', 0),
        'global' => env('RABBITMQ_QOS_GLOBAL', false),
    ],

    // Configurações de DLQ padrão
    'dlq' => [
        'enabled' => env('RABBITMQ_DLQ_ENABLED', true),
        'suffix' => env('RABBITMQ_DLQ_SUFFIX', '.dlq'),
        'dlx_suffix' => env('RABBITMQ_DLX_SUFFIX', '.dlx'),
        'message_ttl' => env('RABBITMQ_DLQ_MESSAGE_TTL', null),
    ],

    // Configurações de retry
    'retry' => [
        'enabled' => env('RABBITMQ_RETRY_ENABLED', true),
        'max_attempts' => env('RABBITMQ_RETRY_MAX_ATTEMPTS', 3),
        'delay_ms' => env('RABBITMQ_RETRY_DELAY_MS', 5000),
        'levels' => [1000, 5000, 30000], // delays em ms para retry multi-nível
    ],

    // Configurações de publisher
    'publisher' => [
        'confirm_mode' => env('RABBITMQ_PUBLISHER_CONFIRM', true),
        'confirm_timeout' => env('RABBITMQ_CONFIRM_TIMEOUT', 5),
        'persistent' => env('RABBITMQ_PUBLISHER_PERSISTENT', true),
    ],

    // Configurações de consumer
    'consumer' => [
        'prefetch_count' => env('RABBITMQ_CONSUMER_PREFETCH', 1),
        'timeout' => env('RABBITMQ_CONSUMER_TIMEOUT', 0),
        'stop_on_error' => env('RABBITMQ_CONSUMER_STOP_ON_ERROR', false),
    ],
];
