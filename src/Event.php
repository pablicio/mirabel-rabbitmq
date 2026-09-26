<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ;

use InvalidArgumentException;
use LogicException;
use Mirabel\RabbitMQ\Connection\ConnectionFactoryInterface;
use Mirabel\RabbitMQ\Connection\PhpAmqpConnectionFactory;
use Mirabel\RabbitMQ\Connection\QuietClose;
use Mirabel\RabbitMQ\Observability\TelemetryRuntime;
use Mirabel\RabbitMQ\Outbox\OutboxMessage;
use Mirabel\RabbitMQ\Publishing\ChannelPublisher;
use Mirabel\RabbitMQ\Publishing\PublisherRuntime;
use Mirabel\RabbitMQ\Serialization\JsonSerializer;
use Mirabel\RabbitMQ\Serialization\SerializerInterface;
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

    protected function serializer(): SerializerInterface
    {
        return new JsonSerializer();
    }

    /**
     * Publishes the event to the configured exchange.
     *
     * Transport failures are retried with exponential backoff up to
     * MB_RABBITMQ_PUBLISH_RETRIES times (default 3), then rethrown. The same
     * message_id is kept across retries, so consumers can deduplicate a message
     * that reached the broker right before the connection dropped.
     */
    public function publish(
        ?string $routingKey = null,
        ?string $messageId = null,
        ?string $correlationId = null,
        ?string $idempotencyKey = null,
        ?int $schemaVersion = null,
    ): void {
        $config = ConnectionConfig::fromEnvironment();
        $outboxMessage = $this->buildOutboxMessage($config, $routingKey, $messageId, $correlationId, $idempotencyKey, $schemaVersion);
        $message = new AMQPMessage($outboxMessage->body, $outboxMessage->properties);
        $context = [
            'event' => static::class,
            'exchange' => $outboxMessage->exchange,
            'routing_key' => $outboxMessage->routingKey,
            'message_id' => $outboxMessage->id,
        ];
        $attempt = 0;

        while (true) {
            try {
                $this->publishAttempt($config, $message, $outboxMessage->routingKey);
                $this->logger()->debug('RabbitMQ event published.', $context);
                $this->recordTelemetry('published', $context);

                return;
            } catch (\Throwable $exception) {
                $attempt++;
                if ($attempt > $config->publishRetries) {
                    $this->logger()->error('RabbitMQ event publication failed.', $context + [
                        'attempt' => $attempt,
                        'exception' => $exception,
                    ]);
                    $this->recordTelemetry('publish_failed', $context + ['attempt' => $attempt]);
                    throw $exception;
                }

                $delay = $config->backoffDelayMs($attempt);
                $this->logger()->warning('RabbitMQ event publication will retry.', $context + [
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                    'exception' => $exception,
                ]);
                $this->recordTelemetry('publish_retry', $context + ['attempt' => $attempt, 'delay_ms' => $delay]);
                usleep($delay * 1000);
            }
        }
    }

    /**
     * Builds the message exactly as publish() would send it, without touching
     * the network. Store it in an outbox table inside the same database
     * transaction as the business change, and let OutboxDispatcher publish it.
     */
    public function toOutboxMessage(
        ?string $routingKey = null,
        ?string $messageId = null,
        ?string $correlationId = null,
        ?string $idempotencyKey = null,
        ?int $schemaVersion = null,
    ): OutboxMessage {
        return $this->buildOutboxMessage(
            ConnectionConfig::fromEnvironment(),
            $routingKey,
            $messageId,
            $correlationId,
            $idempotencyKey,
            $schemaVersion,
        );
    }

    private function buildOutboxMessage(
        ConnectionConfig $config,
        ?string $routingKey,
        ?string $messageId,
        ?string $correlationId,
        ?string $idempotencyKey,
        ?int $schemaVersion,
    ): OutboxMessage {
        $properties = $this->messageProperties($messageId, $correlationId, $idempotencyKey, $schemaVersion);

        return new OutboxMessage(
            id: (string) $properties['message_id'],
            exchange: $config->exchange,
            routingKey: $routingKey ?? self::defaultRoutingKey(),
            body: $this->serializer()->encode($this->payload),
            properties: $properties,
        );
    }

    private static function defaultRoutingKey(): string
    {
        $routingKey = isset(static::$routingKey) ? static::$routingKey : '';
        if ($routingKey === '') {
            throw new LogicException(sprintf(
                'Event %s must declare "public static string $routingKey" or receive a routing key in publish().',
                static::class,
            ));
        }

        return $routingKey;
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
        if (PublisherRuntime::isEnabled()) {
            PublisherRuntime::publish($this->connectionFactory(), $config, $message, $routingKey);

            return;
        }

        $connection = $this->connectionFactory()->connect($config);
        $channel = null;

        try {
            $channel = $connection->channel();
            ChannelPublisher::prepare($channel, $config, $config->exchange);
            ChannelPublisher::publish($channel, $config, $message, $config->exchange, $routingKey);
        } finally {
            QuietClose::all($channel, $connection);
        }
    }

    private function recordTelemetry(string $event, array $context = []): void
    {
        TelemetryRuntime::record($event, $context, $this->logger());
    }
}
