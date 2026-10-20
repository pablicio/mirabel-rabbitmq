<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Management;

use Mirabel\RabbitMQ\ConnectionConfig;
use Mirabel\RabbitMQ\Exception\ConnectionException;

/**
 * The broker's HTTP management API (the plugin behind the web panel), for
 * what AMQP does not tell: how long the oldest message waits and how fast a
 * queue moves. The address comes from MB_RABBITMQ_MANAGEMENT_URL, by default
 * http://<MB_RABBITMQ_HOST>:15672, with the same user and password.
 */
final class ManagementApi
{
    public function __construct(private readonly ConnectionConfig $config)
    {
    }

    public function queue(string $name): QueueStats
    {
        $url = sprintf(
            '%s/api/queues/%s/%s?msg_rates_age=60&msg_rates_incr=5',
            rtrim(self::baseUrl($this->config), '/'),
            rawurlencode($this->config->vhost),
            rawurlencode($name),
        );
        $context = stream_context_create(['http' => [
            'header' => 'Authorization: Basic ' . base64_encode($this->config->user . ':' . $this->config->password),
            'timeout' => $this->config->connectTimeout,
            'ignore_errors' => true,
        ]]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new ConnectionException(sprintf('Cannot reach the RabbitMQ management API at %s', self::baseUrl($this->config)));
        }
        $status = (int) (explode(' ', $http_response_header[0] ?? '')[1] ?? 0);
        if ($status === 404) {
            return new QueueStats($name, 0, 0, 0, null, 0.0, 0.0);
        }
        if ($status !== 200) {
            throw new ConnectionException(sprintf('The RabbitMQ management API answered %d for queue "%s"', $status, $name));
        }

        return QueueStats::fromApi((array) json_decode($body, true, flags: JSON_THROW_ON_ERROR), time());
    }

    private static function baseUrl(ConnectionConfig $config): string
    {
        $url = getenv('MB_RABBITMQ_MANAGEMENT_URL');

        return $url !== false && $url !== '' ? $url : "http://{$config->host}:15672";
    }
}
