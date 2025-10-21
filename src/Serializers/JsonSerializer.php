<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq\Serializers;

use Pablicio\MirabelRabbitmq\Contracts\SerializerInterface;
use Pablicio\MirabelRabbitmq\Exceptions\RabbitMQException;

class JsonSerializer implements SerializerInterface
{
    public function serialize(mixed $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw RabbitMQException::serializationFailed($e->getMessage());
        }
    }

    public function deserialize(string $data): mixed
    {
        try {
            return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw RabbitMQException::deserializationFailed($e->getMessage());
        }
    }

    public function getContentType(): string
    {
        return 'application/json';
    }
}
