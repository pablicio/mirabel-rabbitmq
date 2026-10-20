<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

final class Envelope
{
    public readonly mixed $body;
    public readonly string $messageId;
    public readonly string $correlationId;
    public readonly string $type;
    public readonly int $timestamp;
    public readonly string $idempotencyKey;
    public readonly int $schemaVersion;
    public readonly string $routingKey;
    public readonly int $priority;

    private ?string $response = null;

    /**
     * @param \Closure(AMQPMessage, bool, string): void|null $nackPolicy applied on
     *        nack()/reject() without requeue. The Worker uses it to route the
     *        message: nack goes to retry (or to the error queue on the last
     *        attempt); reject goes straight to the error queue.
     */
    public function __construct(
        private readonly AMQPChannel $channel,
        private readonly AMQPMessage $message,
        mixed $body,
        public readonly int $attempt = 1,
        private readonly ?\Closure $nackPolicy = null,
    ) {
        $this->body = $body;
        $this->messageId = $message->has('message_id') ? (string) $message->get('message_id') : '';
        $this->correlationId = $message->has('correlation_id') ? (string) $message->get('correlation_id') : '';
        $this->type = $message->has('type') ? (string) $message->get('type') : '';
        $this->timestamp = $message->has('timestamp') ? (int) $message->get('timestamp') : 0;
        $this->idempotencyKey = $this->idempotencyKey($message);
        $this->schemaVersion = $this->schemaVersion($message);
        $this->routingKey = (string) ($message->getRoutingKey() ?? '');
        $this->priority = $message->has('priority') ? (int) $message->get('priority') : 0;
    }

    /**
     * Why a message is in an error queue: reason (exception, nack, reject or
     * poison), the attempt it failed on, the exception, the text the handler
     * passed to reject()/nack() and when. Null for a message that never
     * failed.
     *
     * @return array{reason: string, attempts: int, exception: ?string, detail: ?string, failed_at: int}|null
     */
    public function failure(): ?array
    {
        $headers = $this->headers();
        if (!isset($headers['x-mirabel-failure-reason'])) {
            return null;
        }

        return [
            'reason' => (string) $headers['x-mirabel-failure-reason'],
            'attempts' => (int) ($headers['x-mirabel-attempts'] ?? 0),
            'exception' => isset($headers['x-mirabel-exception']) ? (string) $headers['x-mirabel-exception'] : null,
            'detail' => isset($headers['x-mirabel-detail']) ? (string) $headers['x-mirabel-detail'] : null,
            'failed_at' => (int) ($headers['x-mirabel-failed-at'] ?? 0),
        ];
    }

    /**
     * The message as Mirabel sees it, for printing and logs: the event, the
     * identity, the contract version and the body. Times are ISO 8601 in UTC.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $when = static fn (int $seconds): ?string => $seconds > 0 ? gmdate('Y-m-d\TH:i:s\Z', $seconds) : null;
        $properties = $this->message->get_properties();
        $failure = $this->failure();
        if ($failure !== null) {
            $failure['failed_at'] = $when($failure['failed_at']);
        }

        return array_filter([
            'type' => $this->type,
            'routing_key' => $this->routingKey,
            'message_id' => $this->messageId,
            'correlation_id' => $this->correlationId,
            'idempotency_key' => $this->idempotencyKey,
            'schema_version' => $this->schemaVersion,
            'priority' => $this->priority ?: null,
            'published_at' => $when($this->timestamp),
            'persistent' => ($properties['delivery_mode'] ?? 1) === 2,
            'content_type' => $properties['content_type'] ?? null,
            'attempt' => $this->attempt,
            'body' => $this->body,
            'failure' => $failure,
        ], static fn (mixed $value): bool => $value !== '' && $value !== null);
    }

    public function ack(): void
    {
        $this->message->ack();
        $this->response = 'ack';
    }

    /**
     * Temporary failure. Without requeue the message waits in the worker's
     * retry queue (or goes to the error queue on the last attempt). With
     * requeue it goes back to the same queue immediately, with no delay.
     * $because is written on the message if it reaches the error queue.
     */
    public function nack(bool $requeue = false, string $because = ''): void
    {
        if (!$requeue && $this->nackPolicy !== null) {
            ($this->nackPolicy)($this->message, true, $because);
        } else {
            $this->message->nack($requeue);
        }
        $this->response = 'nack';
    }

    /**
     * Permanent failure: the message can never succeed (invalid payload,
     * unknown schema). Without requeue it goes straight to the error queue,
     * with $because written on it for whoever reads the error queue.
     */
    public function reject(bool $requeue = false, string $because = ''): void
    {
        if (!$requeue && $this->nackPolicy !== null) {
            ($this->nackPolicy)($this->message, false, $because);
        } else {
            $this->message->reject($requeue);
        }
        $this->response = 'reject';
    }

    /** 'ack', 'nack', 'reject' or null while the handler has not answered yet. */
    public function response(): ?string
    {
        return $this->response;
    }

    public function isResponded(): bool
    {
        return $this->response !== null;
    }

    public function isRedelivered(): bool
    {
        return (bool) $this->message->isRedelivered();
    }

    public function message(): AMQPMessage
    {
        return $this->message;
    }

    /** @return array<string, mixed> */
    private function headers(): array
    {
        if (!$this->message->has('application_headers')) {
            return [];
        }

        $headers = $this->message->get('application_headers');

        return method_exists($headers, 'getNativeData') ? $headers->getNativeData() : [];
    }

    private function idempotencyKey(AMQPMessage $message): string
    {
        if (!$message->has('application_headers')) {
            return '';
        }

        $headers = $message->get('application_headers');
        if (!method_exists($headers, 'getNativeData')) {
            return '';
        }

        return (string) ($headers->getNativeData()['x-idempotency-key'] ?? '');
    }

    private function schemaVersion(AMQPMessage $message): int
    {
        if (!$message->has('application_headers')) {
            return 1;
        }

        $headers = $message->get('application_headers');
        if (!method_exists($headers, 'getNativeData')) {
            return 1;
        }

        return (int) ($headers->getNativeData()['x-schema-version'] ?? 1);
    }
}