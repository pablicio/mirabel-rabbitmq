<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ;

use Mirabel\RabbitMQ\Connection\ConnectionFactoryInterface;
use Mirabel\RabbitMQ\Connection\PhpAmqpConnectionFactory;
use Mirabel\RabbitMQ\Observability\TelemetryRuntime;
use Mirabel\RabbitMQ\Outbox\OutboxMessage;
use Mirabel\RabbitMQ\Publishing\PublisherRuntime;
use Mirabel\RabbitMQ\Serialization\JsonSerializer;
use InvalidArgumentException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

abstract class Event
{
    public static string $routingKey;
    public static int $schemaVersion = 1;

    public function __construct(public mixed $payload)
    {
    }

    protected function connectionFactory(): ConnectionFactoryInterface
    {
        return new PhpAmqpConnectionFactory();
    }

    protected function logger(): LoggerInterface
    {
        return new NullLogger();
    }

    private function recordTelemetry(string $event, array $context = []): void
    {
        TelemetryRuntime::record($event, $context, $this->logger());
    }

    public function publish(
        ?string $routingKey = null,
        ?string $messageId = null,
        ?string $correlationId = null,
        ?string $idempotencyKey = null,
        ?int $schemaVersion = null,
    ): void
    {
        $config = ConnectionConfig::fromEnvironment();
        $outboxMessage = $this->toOutboxMessage($routingKey, $messageId, $correlationId, $idempotencyKey, $schemaVersion);
        $message = new AMQPMessage($outboxMessage->body, $outboxMessage->properties);
        $attempt = 0;

        while (true) {
            try {
                $this->publishAttempt($config, $message, $outboxMessage->routingKey);
                $this->logger()->debug('RabbitMQ event published.', [
                    'event' => static::class,
                    'exchange' => $outboxMessage->exchange,
                    'routing_key' => $outboxMessage->routingKey,
                    'message_id' => $outboxMessage->id,
                ]);
                $this->recordTelemetry('published', [
                    'event' => static::class,
                    'exchange' => $outboxMessage->exchange,
                    'routing_key' => $outboxMessage->routingKey,
                    'message_id' => $outboxMessage->id,
                ]);

                return;
            } catch (\Throwable $exception) {
                $attempt++;
                if ($config->reconnectAttempts > 0 && $attempt > $config->reconnectAttempts) {
                    $this->logger()->error('RabbitMQ event publication failed.', [
                        'event' => static::class,
                        'exchange' => $outboxMessage->exchange,
                        'routing_key' => $outboxMessage->routingKey,
                        'message_id' => $outboxMessage->id,
                        'attempt' => $attempt,
                        'exception' => $exception,
                    ]);
                    throw $exception;
                }

                $delay = min(
                    $config->reconnectMaxDelayMs,
                    $config->reconnectDelayMs * (2 ** min($attempt - 1, 10)),
                );
                $this->logger()->warning('RabbitMQ event publication will retry.', [
                    'event' => static::class,
                    'exchange' => $outboxMessage->exchange,
                    'routing_key' => $outboxMessage->routingKey,
                    'message_id' => $outboxMessage->id,
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                    'exception' => $exception,
                ]);
                $this->recordTelemetry('publish_retry', [
                    'event' => static::class,
                    'exchange' => $outboxMessage->exchange,
                    'routing_key' => $outboxMessage->routingKey,
                    'message_id' => $outboxMessage->id,
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                ]);
                usleep($delay * 1000);
            }
        }
    }

    public function toOutboxMessage(
        ?string $routingKey = null,
        ?string $messageId = null,
        ?string $correlationId = null,
        ?string $idempotencyKey = null,
        ?int $schemaVersion = null,
    ): OutboxMessage {
        $properties = $this->messageProperties($messageId, $correlationId, $idempotencyKey, $schemaVersion);

        return new OutboxMessage(
            id: (string) $properties['message_id'],
            exchange: ConnectionConfig::fromEnvironment()->exchange,
            routingKey: $routingKey ?? static::$routingKey,
            body: (new JsonSerializer())->encode($this->payload),
            properties: $properties,
        );
    }

    private function messageProperties(?string $messageId, ?string $correlationId, ?string $idempotencyKey, ?int $schemaVersion): array
    {
        $resolvedSchemaVersion = $schemaVersion
            ?? (defined(static::class . '::SCHEMA_VERSION')
                ? (int) constant(static::class . '::SCHEMA_VERSION')
                : static::$schemaVersion);
        if ($resolvedSchemaVersion < 1) {
            throw new InvalidArgumentException('Event schema version must be greater than zero.');
        }

        $properties = [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'message_id' => $messageId ?? bin2hex(random_bytes(16)),
            'type' => static::class,
            'timestamp' => time(),
        ];

        $headers = [
            'x-schema-version' => $resolvedSchemaVersion,
        ];

        if ($correlationId !== null) {
            $properties['correlation_id'] = $correlationId;
        }

        if ($idempotencyKey !== null) {
            $headers['x-idempotency-key'] = $idempotencyKey;
        }

        $properties['application_headers'] = new AMQPTable($headers);

        return $properties;
    }

    private function publishAttempt(ConnectionConfig $config, AMQPMessage $message, string $routingKey): void
    {
        if (in_array(strtolower((string) getenv('MB_RABBITMQ_REUSE_CONNECTION')), ['1', 'true', 'yes'], true)) {
            PublisherRuntime::publish($this->connectionFactory(), $config, $message, $routingKey);

            return;
        }

        $connection = $this->connectionFactory()->connect($config);
        $channel = null;

        try {
            $channel = $connection->channel();
            $channel->exchange_declare($config->exchange, $config->exchangeType, false, true, false);
            if ($config->publisherConfirms) {
                $channel->confirm_select();
            }
            $channel->basic_publish($message, $config->exchange, $routingKey);
            if ($config->publisherConfirms) {
                $channel->wait_for_pending_acks();
            }
        } finally {
            if ($channel !== null) {
                $channel->close();
            }
            $connection->close();
        }
    }
}