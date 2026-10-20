<?php

// Read by the Laravel bridge. Each key becomes MB_RABBITMQ_<KEY> in the
// process environment at boot, so the values survive `config:cache`.
// null leaves the library default in place.

return [
    'host' => env('MB_RABBITMQ_HOST'),
    'port' => env('MB_RABBITMQ_PORT'),
    'user' => env('MB_RABBITMQ_USER'),
    'password' => env('MB_RABBITMQ_PASSWORD'),
    'vhost' => env('MB_RABBITMQ_VHOST'),
    'exchange' => env('MB_RABBITMQ_EXCHANGE'),
    'exchange_type' => env('MB_RABBITMQ_EXCHANGE_TYPE'),
    'publisher_confirms' => env('MB_RABBITMQ_PUBLISHER_CONFIRMS'),
    'connect_timeout' => env('MB_RABBITMQ_CONNECT_TIMEOUT'),
    'read_write_timeout' => env('MB_RABBITMQ_READ_WRITE_TIMEOUT'),
    'heartbeat' => env('MB_RABBITMQ_HEARTBEAT'),
    'reconnect_attempts' => env('MB_RABBITMQ_RECONNECT_ATTEMPTS'),
    'reconnect_delay_ms' => env('MB_RABBITMQ_RECONNECT_DELAY_MS'),
    'reconnect_max_delay_ms' => env('MB_RABBITMQ_RECONNECT_MAX_DELAY_MS'),
    'publish_retries' => env('MB_RABBITMQ_PUBLISH_RETRIES'),

    // Laravel log channel for the library's own messages (reconnects,
    // retries, error queue). null is the default channel; 'null' mutes it.
    'log_channel' => env('MB_RABBITMQ_LOG_CHANNEL'),
];
