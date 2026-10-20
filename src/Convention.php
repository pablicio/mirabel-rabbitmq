<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ;

/**
 * Reads what an event or a worker declares and fills in the rest.
 *
 * Precedence, from strongest to weakest: an UPPERCASE constant, a legacy
 * lowercase constant (`routing_keys`, `options`, `retry_options`), a public
 * static property, and finally the convention below.
 *
 *   PedidoPago                → routing key "pedido.pago"
 *   OrderReceivedEvent        → routing key "order.received"
 *   EmitirNotaFiscal (worker) → queue "emitir-nota-fiscal"
 *   SendEmailWorker           → queue "send-email"
 */
final class Convention
{
    /** @param class-string<Event> $event */
    public static function routingKey(string $event): string
    {
        if (defined($event . '::ROUTING_KEY')) {
            return (string) constant($event . '::ROUTING_KEY');
        }

        if (isset($event::$routingKey) && $event::$routingKey !== '') {
            return $event::$routingKey;
        }

        return implode('.', self::words($event, 'Event'));
    }

    /** @param class-string<Worker> $worker */
    public static function queue(string $worker): string
    {
        if (defined($worker . '::QUEUE')) {
            return (string) constant($worker . '::QUEUE');
        }

        if (isset($worker::$queue) && $worker::$queue !== '') {
            return $worker::$queue;
        }

        return implode('-', self::words($worker, 'Worker'));
    }

    /**
     * ROUTING_KEYS plus the routing key of each class in EVENTS, in that
     * order and without repetition.
     *
     * @param class-string<Worker> $worker
     * @return list<string>
     */
    public static function routingKeys(string $worker): array
    {
        $keys = [];
        if (defined($worker . '::EVENTS')) {
            foreach ((array) constant($worker . '::EVENTS') as $event) {
                $keys[] = self::routingKey($event);
            }
        }

        if (defined($worker . '::ROUTING_KEYS')) {
            $keys = [...$keys, ...(array) constant($worker . '::ROUTING_KEYS')];
        } elseif (defined($worker . '::routing_keys')) {
            $keys = [...$keys, ...(array) constant($worker . '::routing_keys')];
        } else {
            $keys = [...$keys, ...$worker::$routingKeys];
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param class-string<Worker> $worker
     * @return array{delay: int, max_attempts: int}
     */
    public static function retry(string $worker): array
    {
        if (defined($worker . '::ATTEMPTS') || defined($worker . '::RETRY_DELAY')) {
            return [
                'delay' => (int) (self::constant($worker, 'RETRY_DELAY') ?? 0),
                'max_attempts' => (int) (self::constant($worker, 'ATTEMPTS') ?? 1),
            ];
        }

        if (defined($worker . '::retry_options')) {
            $legacy = (array) constant($worker . '::retry_options');

            return [
                'delay' => (int) ($legacy['x-message-ttl'] ?? 0),
                'max_attempts' => (int) ($legacy['max-attempts'] ?? 1),
            ];
        }

        return [
            'delay' => (int) ($worker::$retry['delay'] ?? 0),
            'max_attempts' => (int) ($worker::$retry['max_attempts'] ?? 1),
        ];
    }

    /**
     * The options array the worker passes to the broker, with PREFETCH,
     * QUEUE_TYPE and EXCHANGE_TYPE folded in.
     *
     * @param class-string<Worker> $worker
     * @return array<string, mixed>
     */
    public static function options(string $worker): array
    {
        $options = defined($worker . '::options')
            ? (array) constant($worker . '::options')
            : $worker::$options;

        foreach ([
            'PREFETCH' => 'qos_prefetch_count',
            'QUEUE_TYPE' => 'queue_type',
            'EXCHANGE_TYPE' => 'exchange_type',
            'MAX_LENGTH' => 'max_length',
            'OVERFLOW' => 'overflow',
        ] as $constant => $option) {
            $value = self::constant($worker, $constant);
            if ($value !== null) {
                $options[$option] = $value;
            }
        }

        return $options;
    }

    private static function constant(string $class, string $name): mixed
    {
        return defined($class . '::' . $name) ? constant($class . '::' . $name) : null;
    }

    /** @return list<string> "PedidoPagoEvent", "Event" → ["pedido", "pago"] */
    private static function words(string $class, string $suffix): array
    {
        $name = substr(strrchr('\\' . $class, '\\'), 1);
        if ($name !== $suffix && str_ends_with($name, $suffix)) {
            $name = substr($name, 0, -strlen($suffix));
        }

        preg_match_all('/[A-Z]+(?![a-z])|[A-Z][a-z0-9]*|[a-z0-9]+/', $name, $matches);

        return array_map('strtolower', $matches[0]);
    }
}
