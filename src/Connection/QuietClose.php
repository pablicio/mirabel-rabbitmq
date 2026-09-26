<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Connection;

/**
 * Closes channels and connections during cleanup without letting a failure to
 * close (typically on an already broken socket) hide the exception that caused
 * the cleanup in the first place.
 */
final class QuietClose
{
    public static function all(?object ...$resources): void
    {
        foreach ($resources as $resource) {
            if ($resource === null) {
                continue;
            }

            try {
                $resource->close();
            } catch (\Throwable) {
                // The resource is already unusable; nothing left to release.
            }
        }
    }
}
