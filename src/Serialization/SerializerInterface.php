<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Serialization;

interface SerializerInterface
{
    public function encode(mixed $value): string;

    public function decode(string $body): mixed;
}