<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Laravel;

/**
 * The library reads its connection with getenv(). After `config:cache`,
 * Laravel stops loading .env into the process, so the cached config is
 * copied back into the environment. A variable the server really set (Docker,
 * systemd, Kubernetes) always wins: only what is missing is filled in.
 */
final class Environment
{
    /**
     * @param array<string, scalar|null> $config keys like "host", "exchange"
     * @return list<string> the variables that were exported
     */
    public static function export(array $config): array
    {
        $exported = [];
        foreach ($config as $key => $value) {
            if (str_starts_with((string) $key, 'log_') || $value === null || $value === '') {
                continue;
            }

            $name = 'MB_RABBITMQ_' . strtoupper((string) $key);
            if (getenv($name) !== false) {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            putenv("{$name}={$value}");
            $exported[] = $name;
        }

        return $exported;
    }
}
