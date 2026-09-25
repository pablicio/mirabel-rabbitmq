<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ;

use Mirabel\RabbitMQ\Connection\ConnectionFactoryInterface;
use Mirabel\RabbitMQ\Connection\PhpAmqpConnectionFactory;
use Mirabel\RabbitMQ\Idempotency\IdempotencyStoreInterface;
use Mirabel\RabbitMQ\Observability\TelemetryRuntime;
use Mirabel\RabbitMQ\Serialization\JsonSerializer;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
abstract class Worker
{
    public static string $queue;
    public static array $routingKeys = [];
    public static array $retry = [];
    public static array $options = [];

    private ?object $activeChannel = null;
    private ?string $activeErrorExchange = null;
    private ?string $activeQueue = null;
    private array $activeRetry = [];
    private bool $shutdownRequested = false;

    protected function connectionFactory(): ConnectionFactoryInterface
    {
        return new PhpAmqpConnectionFactory();
    }

    protected function logger(): LoggerInterface
    {
        return new NullLogger();
    }

    protected function idempotencyStore(): ?IdempotencyStoreInterface
    {
        return null;
    }

    private function recordTelemetry(string $event, array $context = []): void
    {
        TelemetryRuntime::record($event, $context, $this->logger());
    }

    public function subscribe(): void
    {
        $config = ConnectionConfig::fromEnvironment();
        $this->registerSignalHandlers();
        $reconnectAttempt = 0;

        while (!$this->shutdownRequested) {
            try {
                $this->consume($config);
                $reconnectAttempt = 0;
            } catch (\Throwable $exception) {
                if ($this->shutdownRequested) {
                    break;
                }

                $reconnectAttempt++;
                if ($config->reconnectAttempts > 0 && $reconnectAttempt > $config->reconnectAttempts) {
                    $this->logger()->error('RabbitMQ worker stopped after reconnect attempts were exhausted.', [
                        'worker' => static::class,
                        'queue' => $this->queueName(),
                        'attempt' => $reconnectAttempt,
                        'exception' => $exception,
                    ]);
                    throw $exception;
                }

                $delay = min(
                    $config->reconnectMaxDelayMs,
                    $config->reconnectDelayMs * (2 ** min($reconnectAttempt - 1, 10)),
                );
                $this->logger()->warning('RabbitMQ worker connection will retry.', [
                    'worker' => static::class,
                    'queue' => $this->queueName(),
                    'attempt' => $reconnectAttempt,
                    'delay_ms' => $delay,
                    'exception' => $exception,
                ]);
                usleep($delay * 1000);
            }
        }

        if ($this->shutdownRequested) {
            $this->logger()->info('RabbitMQ worker stopped gracefully.', [
                'worker' => static::class,
                'queue' => $this->queueName(),
            ]);
        }
    }

    private function consume(ConnectionConfig $config): void
    {
        $queue = $this->queueName();
        $routingKeys = $this->routingKeys();
        $options = $this->options();
        $retry = $this->retrySettings();
        $connection = $this->connectionFactory()->connect($config);
        $channel = $connection->channel();
        $retryExchange = $queue . '.retry';
        $errorExchange = $queue . '.error';
        $retryQueue = $queue . '.retry';
        $errorQueue = $queue . '.error';
        $routingKey = $routingKeys[0] ?? $queue;

        try {
            $this->declareTopology(
                $channel,
                $config,
                $retryExchange,
                $errorExchange,
                $retryQueue,
                $errorQueue,
                $routingKey,
                $routingKeys,
                $options['exchange_type'] ?? $config->exchangeType,
                $retry,
            );

            $channel->basic_qos(
                $options['qos_prefetch_size'] ?? 0,
                $options['qos_prefetch_count'] ?? 1,
                $options['qos_a_global'] ?? false,
            );
            $channel->basic_consume(
                $queue,
                $options['consume_consumer_tag'] ?? '',
                $options['consume_no_local'] ?? false,
                $options['consume_no_ack'] ?? false,
                $options['consume_exclusive'] ?? false,
                $options['consume_nowait'] ?? false,
                function (AMQPMessage $message) use ($channel, $config, $errorExchange, $retry, $queue): void {
                    $this->process($channel, $config, $errorExchange, $message, $retry, $queue);
                },
                $options['consume_ticket'] ?? null,
            );

            while ($channel->callbacks && !$this->shutdownRequested) {
                $channel->wait();
            }
        } finally {
            $channel->close();
            $connection->close();
        }
    }

    private function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->shutdownRequested = true;
        });
        pcntl_signal(SIGINT, function (): void {
            $this->shutdownRequested = true;
        });
    }

    public function handle(Envelope $envelope): void
    {
        throw new \LogicException('Worker must implement handle() or work().');
    }

    /** @return array{queue: string, active_queue: ?string, connected: bool, shutdown_requested: bool} */
    public function health(): array
    {
        return [
            'queue' => $this->queueName(),
            'active_queue' => $this->activeQueue,
            'connected' => !$this->shutdownRequested,
            'shutdown_requested' => $this->shutdownRequested,
        ];
    }

    public function ack(AMQPMessage $message): string
    {
        $message->ack();

        return 'ack';
    }

    public function nack(AMQPMessage $message, bool $requeue = false): string
    {
        if ($this->activeChannel !== null
            && $this->activeErrorExchange !== null
            && $this->activeQueue !== null
            && $this->attempts($message, $this->activeQueue) >= (int) ($this->activeRetry['max_attempts'] ?? 1)
        ) {
            $this->activeChannel->basic_publish($message, $this->activeErrorExchange, $this->activeQueue);
            $message->ack();

            return 'nack';
        }

        $message->nack($requeue);

        return 'nack';
    }

    public function reject(AMQPMessage $message, bool $requeue = false): string
    {
        $message->reject($requeue);

        return 'reject';
    }

    private function declareTopology(
        object $channel,
        ConnectionConfig $config,
        string $retryExchange,
        string $errorExchange,
        string $retryQueue,
        string $errorQueue,
        string $routingKey,
        array $routingKeys,
        string $exchangeType,
        array $retry,
    ): void {
        $channel->exchange_declare($config->exchange, $exchangeType, false, true, false);
        $channel->exchange_declare($retryExchange, $exchangeType, false, true, false);
        $channel->exchange_declare($errorExchange, $exchangeType, false, true, false);

        $channel->queue_declare($queue = $this->queueName(), false, true, false, false, false, [
            'x-dead-letter-exchange' => ['S', $retryExchange],
            'x-dead-letter-routing-key' => ['S', $queue],
        ]);
        $channel->queue_declare($retryQueue, false, true, false, false, false, [
            'x-message-ttl' => ['I', (int) ($retry['delay'] ?? 0)],
            'x-dead-letter-exchange' => ['S', $config->exchange],
            'x-dead-letter-routing-key' => ['S', $routingKey],
        ]);
        $channel->queue_declare($errorQueue, false, true, false, false, false);

        foreach ($routingKeys as $key) {
            $channel->queue_bind($queue, $config->exchange, $key);
        }
        $channel->queue_bind($retryQueue, $retryExchange, $queue);
        $channel->queue_bind($errorQueue, $errorExchange, $queue);
    }

    private function process(object $channel, ConnectionConfig $config, string $errorExchange, AMQPMessage $message, array $retry, string $queue): void
    {
        $this->activeChannel = $channel;
        $this->activeErrorExchange = $errorExchange;
        $this->activeQueue = $queue;
        $this->activeRetry = $retry;
        $idempotencyStore = $this->idempotencyStore();
        $idempotencyKey = $this->idempotencyKey($message);

        try {
            if ($idempotencyStore !== null && $idempotencyKey !== '' && $idempotencyStore->has($idempotencyKey)) {
                $message->ack();
                $this->logger()->debug('Duplicate RabbitMQ message acknowledged.', [
                    'worker' => static::class,
                    'queue' => $queue,
                    'idempotency_key' => $idempotencyKey,
                ]);
                $this->recordTelemetry('duplicate_message', [
                    'worker' => static::class,
                    'queue' => $queue,
                    'idempotency_key' => $idempotencyKey,
                ]);

                return;
            }

            $result = null;
            if (method_exists($this, 'work')) {
                $result = $this->work($message);
            } else {
                $body = (new JsonSerializer())->decode($message->getBody());
                $this->handle(new Envelope($channel, $message, $body));
            }

            if ($idempotencyStore !== null && $idempotencyKey !== '' && !in_array($result, ['nack', 'reject'], true)) {
                $idempotencyStore->remember($idempotencyKey);
            }

            $this->recordTelemetry('processed', [
                'worker' => static::class,
                'queue' => $queue,
                'idempotency_key' => $idempotencyKey,
                'result' => $result,
            ]);
        } catch (\Throwable $exception) {
            if ($this->attempts($message, $queue) >= (int) ($retry['max_attempts'] ?? 1)) {
                $channel->basic_publish($message, $errorExchange, $queue);
                $message->ack();
                $this->logger()->error('RabbitMQ message sent to the error queue.', [
                    'worker' => static::class,
                    'queue' => $queue,
                    'exception' => $exception,
                ]);
                $this->recordTelemetry('message_sent_to_error_queue', [
                    'worker' => static::class,
                    'queue' => $queue,
                    'attempt' => $this->attempts($message, $queue),
                    'error_exchange' => $errorExchange,
                ]);

                return;
            }

            $message->nack(false, false);
            $this->logger()->warning('RabbitMQ message sent to retry.', [
                'worker' => static::class,
                'queue' => $queue,
                'attempt' => $this->attempts($message, $queue),
                'exception' => $exception,
            ]);
            $this->recordTelemetry('message_retried', [
                'worker' => static::class,
                'queue' => $queue,
                'attempt' => $this->attempts($message, $queue),
            ]);
        } finally {
            $this->activeChannel = null;
            $this->activeErrorExchange = null;
            $this->activeQueue = null;
            $this->activeRetry = [];
        }
    }

    private function attempts(AMQPMessage $message, string $queue): int
    {
        if (!$message->has('application_headers')) {
            return 1;
        }

        $headers = $message->get('application_headers');
        if (!method_exists($headers, 'getNativeData')) {
            return 1;
        }

        $attempts = 1;
        foreach ($headers->getNativeData()['x-death'] ?? [] as $death) {
            if (($death['queue'] ?? null) === $queue . '.retry') {
                $attempts += (int) ($death['count'] ?? 0);
            }
        }

        return $attempts;
    }

    private function idempotencyKey(AMQPMessage $message): string
    {
        if ($message->has('application_headers')) {
            $headers = $message->get('application_headers');
            if (method_exists($headers, 'getNativeData')) {
                $key = $headers->getNativeData()['x-idempotency-key'] ?? null;
                if ($key !== null && $key !== '') {
                    return (string) $key;
                }
            }
        }

        return $message->has('message_id') ? (string) $message->get('message_id') : '';
    }

    private function queueName(): string
    {
        if (defined(static::class . '::QUEUE')) {
            return (string) constant(static::class . '::QUEUE');
        }

        return static::$queue;
    }

    private function routingKeys(): array
    {
        if (defined(static::class . '::routing_keys')) {
            return (array) constant(static::class . '::routing_keys');
        }

        return static::$routingKeys;
    }

    private function options(): array
    {
        if (defined(static::class . '::options')) {
            return (array) constant(static::class . '::options');
        }

        return static::$options;
    }

    private function retrySettings(): array
    {
        if (defined(static::class . '::retry_options')) {
            $legacy = (array) constant(static::class . '::retry_options');

            return [
                'delay' => (int) ($legacy['x-message-ttl'] ?? 0),
                'max_attempts' => (int) ($legacy['max-attempts'] ?? 1),
            ];
        }

        return static::$retry;
    }
}