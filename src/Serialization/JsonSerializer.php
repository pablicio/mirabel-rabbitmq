<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Serialization;

final class JsonSerializer implements SerializerInterface
{
    public function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    public function decode(string $body): mixed
    {
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}