<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq\Exceptions;

use RuntimeException;

class SerializationException extends RuntimeException
{
    public static function serializationFailed(string $reason): self
    {
        return new self("Serialization failed: {$reason}");
    }

    public static function deserializationFailed(string $reason): self
    {
        return new self("Deserialization failed: {$reason}");
    }
}
