<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq\Serializers;

use Pablicio\MirabelRabbitmq\Contracts\SerializerInterface;
use Pablicio\MirabelRabbitmq\Exceptions\RabbitMQException;

class PhpSerializer implements SerializerInterface
{
    public function serialize(mixed $data): string
    {
        try {
            return serialize($data);
        } catch (\Exception $e) {
            throw RabbitMQException::serializationFailed($e->getMessage());
        }
    }

    public function deserialize(string $data): mixed
    {
        try {
            return unserialize($data, ['allowed_classes' => true]);
        } catch (\Exception $e) {
            throw RabbitMQException::deserializationFailed($e->getMessage());
        }
    }

    public function getContentType(): string
    {
        return 'application/x-php-serialized';
    }
}
