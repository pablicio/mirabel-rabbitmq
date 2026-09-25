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

    public function __construct(
        private readonly AMQPChannel $channel,
        private readonly AMQPMessage $message,
        mixed $body,
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
        $this->channel->basic_ack($this->message->getDeliveryTag());
    }

    public function nack(bool $requeue = false): void
    {
        $this->channel->basic_nack($this->message->getDeliveryTag(), false, $requeue);
    }

    public function reject(bool $requeue = false): void
    {
        $this->channel->basic_reject($this->message->getDeliveryTag(), $requeue);
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