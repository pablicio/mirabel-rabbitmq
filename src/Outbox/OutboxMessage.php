<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Outbox;

use InvalidArgumentException;
use PhpAmqpLib\Wire\AMQPTable;

final class OutboxMessage
{
    public function __construct(
        public readonly string $id,
        public readonly string $exchange,
        public readonly string $routingKey,
        public readonly string $body,
        public readonly array $properties = [],
    ) {
        if ($this->id === '' || $this->exchange === '' || $this->routingKey === '') {
            throw new InvalidArgumentException('Outbox message identity and destination are required.');
        }
    }

    /**
     * Plain array (headers included) that can be stored as JSON in an outbox
     * table and turned back into a message with fromArray().
     *
     * @return array{id: string, exchange: string, routing_key: string, body: string, properties: array<string, mixed>}
     */
    public function toArray(): array
    {
        $properties = $this->properties;
        if (isset($properties['application_headers']) && $properties['application_headers'] instanceof AMQPTable) {
            $properties['application_headers'] = $properties['application_headers']->getNativeData();
        }

        return [
            'id' => $this->id,
            'exchange' => $this->exchange,
            'routing_key' => $this->routingKey,
            'body' => $this->body,
            'properties' => $properties,
        ];
    }

    /** @param array{id: string, exchange: string, routing_key: string, body: string, properties?: array<string, mixed>} $data */
    public static function fromArray(array $data): self
    {
        $properties = $data['properties'] ?? [];
        if (isset($properties['application_headers']) && is_array($properties['application_headers'])) {
            $properties['application_headers'] = new AMQPTable($properties['application_headers']);
        }

        return new self(
            id: (string) $data['id'],
            exchange: (string) $data['exchange'],
            routingKey: (string) $data['routing_key'],
            body: (string) $data['body'],
            properties: $properties,
        );
    }
}
