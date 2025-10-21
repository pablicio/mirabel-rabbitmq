<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq\Contracts;

interface SerializerInterface
{
    /**
     * Serialize data for transmission
     *
     * @param mixed $data The data to serialize
     * @return string The serialized data
     */
    public function serialize(mixed $data): string;

    /**
     * Deserialize received data
     *
     * @param string $data The serialized data
     * @return mixed The deserialized data
     */
    public function deserialize(string $data): mixed;

    /**
     * Get the content type for this serializer
     *
     * @return string
     */
    public function getContentType(): string;
}
