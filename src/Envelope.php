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

    private ?string $response = null;

    /**
     * @param \Closure(AMQPMessage, bool): void|null $nackPolicy applied on
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
     */
    public function nack(bool $requeue = false): void
    {
        if (!$requeue && $this->nackPolicy !== null) {
            ($this->nackPolicy)($this->message, true);
        } else {
            $this->message->nack($requeue);
        }
        $this->response = 'nack';
    }

    /**
     * Permanent failure: the message can never succeed (invalid payload,
     * unknown schema). Without requeue it goes straight to the error queue.
     */
    public function reject(bool $requeue = false): void
    {
        if (!$requeue && $this->nackPolicy !== null) {
            ($this->nackPolicy)($this->message, false);
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