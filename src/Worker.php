<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ;

use Mirabel\RabbitMQ\Connection\ConnectionFactoryInterface;
use Mirabel\RabbitMQ\Connection\PhpAmqpConnectionFactory;
use Mirabel\RabbitMQ\Connection\QuietClose;
use Mirabel\RabbitMQ\Exception\TopologyException;
use Mirabel\RabbitMQ\Idempotency\IdempotencyStoreInterface;
use Mirabel\RabbitMQ\Management\ManagementApi;
use Mirabel\RabbitMQ\Management\QueueStats;
use Mirabel\RabbitMQ\Observability\TelemetryRuntime;
use Mirabel\RabbitMQ\Serialization\JsonSerializer;
use Mirabel\RabbitMQ\Serialization\SerializerInterface;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;

/**
 * Topology declared for a worker whose queue is "orders":
 *
 *   main exchange ──routing keys──▶ orders ──nack──▶ orders.retry (exchange)
 *                                     ▲                    │
 *                                     │                    ▼
 *                     default exchange ◀──TTL expired── orders.retry (queue)
 *
 *   last attempt / reject / poison ──▶ orders.error (exchange) ──▶ orders.error (queue)
 *
 * The retry queue dead-letters through the default exchange straight back to
 * "orders", so a retried message never reaches other queues bound to the same
 * routing key.
 */
abstract class Worker
{
    public static string $queue;
    public static array $routingKeys = [];
    public static array $retry = [];
    public static array $options = [];

    /** How long a single wait() blocks before the loop re-checks stop()/signals. */
    private const POLL_SECONDS = 1.0;

    private ?object $activeChannel = null;
    private ?string $activeErrorExchange = null;
    private ?string $activeQueue = null;
    private array $activeRetry = [];
    private bool $shutdownRequested = false;
    private bool $connected = false;

    protected function connectionFactory(): ConnectionFactoryInterface
    {
        return new PhpAmqpConnectionFactory();
    }

    protected function logger(): LoggerInterface
    {
        return Mirabel::logger();
    }

    protected function idempotencyStore(): ?IdempotencyStoreInterface
    {
        return null;
    }

    protected function serializer(): SerializerInterface
    {
        return new JsonSerializer();
    }

    public function subscribe(): void
    {
        $config = ConnectionConfig::fromEnvironment();
        $this->shutdownRequested = false;
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

                if (self::isConfigurationError($exception)) {
                    $this->logger()->error('RabbitMQ refused the worker topology; not retrying.', [
                        'worker' => static::class,
                        'queue' => $this->queueName(),
                        'exception' => $exception,
                    ]);
                    throw $this->refused($exception);
                }

                // A worker that was consuming normally starts a fresh reconnect budget.
                if ($this->connected) {
                    $reconnectAttempt = 0;
                }
                $this->connected = false;

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

                $delay = $config->backoffDelayMs($reconnectAttempt);
                $this->logger()->warning('RabbitMQ worker connection will retry.', [
                    'worker' => static::class,
                    'queue' => $this->queueName(),
                    'attempt' => $reconnectAttempt,
                    'delay_ms' => $delay,
                    'exception' => $exception,
                ]);
                $this->recordTelemetry('worker_reconnect', [
                    'worker' => static::class,
                    'queue' => $this->queueName(),
                    'attempt' => $reconnectAttempt,
                ]);
                usleep($delay * 1000);
            }
        }

        $this->logger()->info('RabbitMQ worker stopped gracefully.', [
            'worker' => static::class,
            'queue' => $this->queueName(),
        ]);
    }

    /**
     * Declares the worker's queues, exchanges and bindings, and leaves. Until
     * the queue exists, an event published with its routing key has nowhere
     * to go and is dropped by the broker; declaring at deploy time, before
     * the first publish, closes that window.
     */
    public function declare(): void
    {
        $config = ConnectionConfig::fromEnvironment();
        $connection = $this->connectionFactory()->connect($config);
        $channel = null;

        try {
            $channel = $connection->channel();
            $this->declareFor($channel, $config);
        } finally {
            QuietClose::all($channel, $connection);
        }
    }

    /**
     * Asks the worker to finish the message in hand and leave subscribe().
     * Safe to call from handle()/work() or from a signal handler.
     */
    public function stop(): void
    {
        $this->shutdownRequested = true;
    }

    public function handle(Envelope $envelope): void
    {
        throw new \LogicException('Worker must implement handle() or work().');
    }

    /**
     * Messages waiting in the worker's queue for a consumer (Ready). A queue
     * that was never declared has none. Delivered but unacknowledged
     * messages are not counted.
     */
    public function pending(): int
    {
        return $this->onQueue(static function (object $channel, string $queue): int {
            [, $messages] = $channel->queue_declare($queue, true);

            return (int) $messages;
        });
    }

    /**
     * Ready and unacknowledged messages, consumers, how long the oldest
     * message waits and how fast the queue moves, from the broker's
     * management API (MB_RABBITMQ_MANAGEMENT_URL).
     */
    public function stats(): QueueStats
    {
        return (new ManagementApi(ConnectionConfig::fromEnvironment()))->queue($this->queueName());
    }

    /**
     * Messages ready in the worker's queue and in its .retry and .error
     * queues, keyed by queue name, in that order. A queue that does not
     * exist counts as empty.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $queue = $this->queueName();
        $counts = [];
        foreach ([$queue, $queue . '.retry', $queue . '.error'] as $name) {
            $counts[$name] = $this->onQueue(static function (object $channel) use ($name): int {
                [, $messages] = $channel->queue_declare($name, true);

                return (int) $messages;
            });
        }

        return $counts;
    }

    /**
     * Deletes the messages waiting in the worker's queue (Ready) and returns
     * how many there were. Messages in the .retry and .error queues, and
     * those delivered but not yet acknowledged, are kept. For test benches
     * and for clearing a queue on purpose: there is no undo.
     */
    public function purge(): int
    {
        return $this->onQueue(static fn (object $channel, string $queue): int => (int) $channel->queue_purge($queue));
    }

    /**
     * The next messages in the worker's queue, without consuming them: they
     * go back to the queue, in order, when this returns.
     *
     * @return list<Envelope>
     */
    public function peek(int $limit = 1): array
    {
        return $this->browse($this->queueName(), $limit);
    }

    /**
     * The messages parked in the worker's error queue, without removing them.
     * Each envelope's failure() says why it is there.
     *
     * @return list<Envelope>
     */
    public function failed(int $limit = 100): array
    {
        return $this->browse($this->queueName() . '.error', $limit);
    }

    /**
     * Moves messages from the error queue back to the worker's queue, oldest
     * first, and returns how many. $which picks them (all when null); the
     * others stay where they are. Each one goes back as a fresh delivery: the
     * same body, identity and contract version, without the retry history and
     * the failure headers, so it gets every attempt again. The broker confirms
     * each copy before the original leaves the error queue.
     *
     * @param (callable(Envelope): bool)|null $which
     */
    public function reprocess(?callable $which = null, ?int $limit = null): int
    {
        $queue = $this->queueName();

        return $this->sweepErrors($which, $limit, static function (object $channel, AMQPMessage $message, ConnectionConfig $config) use ($queue): void {
            $channel->basic_publish(self::withoutFailure($message), '', $queue);
            $channel->wait_for_pending_acks($config->readWriteTimeout);
        });
    }

    /**
     * Deletes the messages of the error queue that $which picks, and returns
     * how many. For messages that were read and understood — a test
     * published by hand, a poison message whose producer was fixed. There is
     * no undo.
     *
     * @param callable(Envelope): bool $which
     */
    public function discard(callable $which): int
    {
        return $this->sweepErrors($which, null, static function (): void {
        });
    }

    /**
     * Walks the error queue once — as many messages as it had when the sweep
     * started, so messages that fail again during the sweep are not read
     * twice — and runs $move on the picked ones before acknowledging them.
     * The rest go back to the error queue, in order, when the channel closes.
     */
    private function sweepErrors(?callable $which, ?int $limit, \Closure $move): int
    {
        $config = ConnectionConfig::fromEnvironment();
        $connection = $this->connectionFactory()->connect($config);
        $channel = null;
        $errorQueue = $this->queueName() . '.error';
        $moved = 0;

        try {
            $channel = $connection->channel();
            [, $total] = $channel->queue_declare($errorQueue, true);
            $channel->confirm_select();
            for ($read = 0; $read < $total && ($limit === null || $moved < $limit); $read++) {
                $message = $channel->basic_get($errorQueue);
                if ($message === null) {
                    break;
                }
                if ($which !== null && !$which($this->envelopeFor($channel, $message))) {
                    continue;
                }

                $move($channel, $message, $config);
                $message->ack();
                $moved++;
            }
        } catch (AMQPProtocolChannelException $exception) {
            if ($exception->getCode() !== 404) {
                throw $exception;
            }
        } finally {
            QuietClose::all($channel, $connection);
        }

        return $moved;
    }

    private function envelopeFor(object $channel, AMQPMessage $message): Envelope
    {
        try {
            $body = $this->serializer()->decode($message->getBody());
        } catch (\JsonException) {
            $body = $message->getBody();
        }

        return new Envelope($channel, $message, $body, $this->attempts($message, $this->queueName()));
    }

    /** @return list<Envelope> */
    private function browse(string $queue, int $limit): array
    {
        $config = ConnectionConfig::fromEnvironment();
        $connection = $this->connectionFactory()->connect($config);
        $channel = null;
        $envelopes = [];

        try {
            $channel = $connection->channel();
            while (count($envelopes) < $limit) {
                $message = $channel->basic_get($queue);
                if ($message === null) {
                    break;
                }

                $envelopes[] = $this->envelopeFor($channel, $message);
            }
        } catch (AMQPProtocolChannelException $exception) {
            if ($exception->getCode() !== 404) {
                throw $exception;
            }
        } finally {
            // Closing without an ack puts every message back where it was.
            QuietClose::all($channel, $connection);
        }

        return $envelopes;
    }

    private static function withoutFailure(AMQPMessage $message): AMQPMessage
    {
        $properties = $message->get_properties();
        if (isset($properties['application_headers']) && method_exists($properties['application_headers'], 'getNativeData')) {
            $headers = array_filter(
                $properties['application_headers']->getNativeData(),
                static fn (string $name): bool => !str_starts_with($name, 'x-mirabel-')
                    && !str_starts_with($name, 'x-death')
                    && !str_starts_with($name, 'x-first-death')
                    && !str_starts_with($name, 'x-last-death'),
                ARRAY_FILTER_USE_KEY,
            );
            $properties['application_headers'] = new AMQPTable($headers);
        }

        return new AMQPMessage($message->getBody(), $properties);
    }

    /** Runs $operation on the worker's queue; a queue that does not exist counts as empty. */
    private function onQueue(\Closure $operation): int
    {
        $config = ConnectionConfig::fromEnvironment();
        $connection = $this->connectionFactory()->connect($config);
        $channel = null;

        try {
            $channel = $connection->channel();

            return $operation($channel, $this->queueName());
        } catch (AMQPProtocolChannelException $exception) {
            if ($exception->getCode() === 404) {
                return 0;
            }
            throw $exception;
        } finally {
            QuietClose::all($channel, $connection);
        }
    }

    /** @return array{queue: string, active_queue: ?string, connected: bool, shutdown_requested: bool} */
    public function health(): array
    {
        return [
            'queue' => $this->queueName(),
            'active_queue' => $this->activeQueue,
            'connected' => $this->connected,
            'shutdown_requested' => $this->shutdownRequested,
        ];
    }

    public function ack(AMQPMessage $message): string
    {
        $message->ack();

        return 'ack';
    }

    /**
     * Temporary failure: the message goes to the retry queue, or to the error
     * queue when it is already on its last attempt. With $requeue it goes
     * straight back to the same queue, with no delay and no attempt counting.
     */
    public function nack(AMQPMessage $message, bool $requeue = false): string
    {
        if (!$requeue && $this->activeChannel !== null) {
            $this->fail($message, true, 'nack');

            return 'nack';
        }

        $message->nack($requeue);

        return 'nack';
    }

    /**
     * Permanent failure: the message cannot succeed, so it is parked in the
     * error queue without spending retries.
     */
    public function reject(AMQPMessage $message, bool $requeue = false, string $because = ''): string
    {
        if (!$requeue && $this->activeChannel !== null) {
            $this->fail($message, false, 'reject', null, $because);

            return 'reject';
        }

        $message->reject($requeue);

        return 'reject';
    }

    private function consume(ConnectionConfig $config): void
    {
        $queue = $this->queueName();
        $options = $this->options();
        $retry = $this->retrySettings();
        $errorExchange = $queue . '.error';
        $connection = $this->connectionFactory()->connect($config);
        $channel = null;

        try {
            $channel = $connection->channel();
            $this->declareFor($channel, $config);

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
            $this->connected = true;

            while ($channel->is_consuming() && !$this->shutdownRequested) {
                try {
                    $channel->wait(null, false, self::POLL_SECONDS);
                } catch (AMQPTimeoutException) {
                    // No message within the poll window; loop to re-check stop().
                }
            }
        } finally {
            $this->connected = false;
            QuietClose::all($channel, $connection);
        }
    }

    private function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->stop();
        });
        pcntl_signal(SIGINT, function (): void {
            $this->stop();
        });
    }

    private function declareFor(object $channel, ConnectionConfig $config): void
    {
        $options = $this->options();
        $this->declareTopology(
            $channel,
            $config,
            $this->queueName(),
            $this->routingKeys(),
            $options['exchange_type'] ?? $config->exchangeType,
            $this->retrySettings(),
            $options['queue_type'] ?? null,
            self::limits($options),
        );
    }

    /**
     * MAX_LENGTH caps the worker's queue; OVERFLOW says what the broker does
     * past it: 'drop-head' (the default) discards the oldest message,
     * 'reject-publish' refuses the new one — a refusal publishers see only
     * with confirms on.
     *
     * @return array<string, array{0: string, 1: int|string}>
     */
    private static function limits(array $options): array
    {
        $limits = [];
        if (isset($options['max_length'])) {
            $limits['x-max-length'] = ['I', (int) $options['max_length']];
        }
        if (isset($options['overflow'])) {
            $limits['x-overflow'] = ['S', (string) $options['overflow']];
        }

        return $limits;
    }

    private function declareTopology(
        object $channel,
        ConnectionConfig $config,
        string $queue,
        array $routingKeys,
        string $exchangeType,
        array $retry,
        ?string $queueType = null,
        array $limits = [],
    ): void {
        // 'quorum' (replicated) or 'classic'; omitted means the broker default.
        $typed = static fn (array $arguments): array => $queueType === null
            ? $arguments
            : $arguments + ['x-queue-type' => ['S', $queueType]];
        $retryExchange = $queue . '.retry';
        $errorExchange = $queue . '.error';
        $retryQueue = $queue . '.retry';
        $errorQueue = $queue . '.error';

        $channel->exchange_declare($config->exchange, $exchangeType, false, true, false);
        $channel->exchange_declare($retryExchange, $exchangeType, false, true, false);
        $channel->exchange_declare($errorExchange, $exchangeType, false, true, false);

        $channel->queue_declare($queue, false, true, false, false, false, $typed([
            'x-dead-letter-exchange' => ['S', $retryExchange],
            'x-dead-letter-routing-key' => ['S', $queue],
        ] + $limits));
        $this->declareRetryQueue($channel, $retryQueue, $typed([
            'x-message-ttl' => ['I', (int) ($retry['delay'] ?? 0)],
            // Default exchange: routes by queue name, straight back to this worker only.
            'x-dead-letter-exchange' => ['S', ''],
            'x-dead-letter-routing-key' => ['S', $queue],
        ]));
        $channel->queue_declare($errorQueue, false, true, false, false, false, $typed([]));

        foreach ($routingKeys as $key) {
            $channel->queue_bind($queue, $config->exchange, $key);
        }
        $channel->queue_bind($retryQueue, $retryExchange, $queue);
        $channel->queue_bind($errorQueue, $errorExchange, $queue);
    }

    /**
     * The retry queue's arguments change when the retry delay changes (and they
     * changed between Mirabel versions). RabbitMQ refuses to redeclare a queue
     * with different arguments, so an empty retry queue is recreated; one that
     * still holds messages is left alone and reported.
     */
    private function declareRetryQueue(object $channel, string $retryQueue, array $arguments): void
    {
        $connection = $channel->getConnection();
        $probe = $connection->channel();

        try {
            $probe->queue_declare($retryQueue, false, true, false, false, false, $arguments);

            return;
        } catch (AMQPProtocolChannelException $exception) {
            if ($exception->getCode() !== 406) {
                throw $exception;
            }
        } finally {
            QuietClose::all($probe);
        }

        $repair = $connection->channel();
        try {
            $repair->queue_delete($retryQueue, false, true);
            $repair->queue_declare($retryQueue, false, true, false, false, false, $arguments);
            $this->logger()->warning('RabbitMQ retry queue recreated with new arguments.', [
                'worker' => static::class,
                'queue' => $retryQueue,
            ]);
        } catch (AMQPProtocolChannelException $exception) {
            throw new TopologyException(sprintf(
                'Retry queue "%s" exists with different arguments and still holds messages. '
                . 'Let it drain (or move its messages) and delete it; the worker will recreate it.',
                $retryQueue,
            ), 0, $exception);
        } finally {
            QuietClose::all($repair);
        }
    }

    /**
     * PRECONDITION_FAILED (406) and ACCESS_REFUSED (403) mean the topology or
     * the permissions are wrong. They are configuration errors, not outages.
     */
    private function refused(\Throwable $exception): TopologyException
    {
        if ($exception instanceof TopologyException) {
            return $exception;
        }

        return new TopologyException(
            sprintf("RabbitMQ refused the topology of queue '%s': %s", $this->queueName(), $exception->getMessage()),
            (int) $exception->getCode(),
        );
    }

    private static function isConfigurationError(\Throwable $exception): bool
    {
        if ($exception instanceof TopologyException) {
            return true;
        }

        return ($exception instanceof AMQPProtocolChannelException
                || $exception instanceof \PhpAmqpLib\Exception\AMQPProtocolConnectionException)
            && in_array($exception->getCode(), [403, 406], true);
    }

    private function process(object $channel, ConnectionConfig $config, string $errorExchange, AMQPMessage $message, array $retry, string $queue): void
    {
        $this->activeChannel = $channel;
        $this->activeErrorExchange = $errorExchange;
        $this->activeQueue = $queue;
        $this->activeRetry = $retry;
        $idempotencyStore = $this->idempotencyStore();
        $idempotencyKey = $this->idempotencyKey($message);
        $envelope = null;

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

            if (method_exists($this, 'work')) {
                $result = $this->work($message);
            } else {
                try {
                    $body = $this->serializer()->decode($message->getBody());
                } catch (\JsonException $exception) {
                    // A body that cannot be decoded will never succeed: park it now.
                    $this->fail($message, false, 'poison', $exception);

                    return;
                }

                $envelope = new Envelope(
                    $channel,
                    $message,
                    $body,
                    $this->attempts($message, $queue),
                    fn (AMQPMessage $failed, bool $retryable, string $because = '') => $this->fail(
                        $failed,
                        $retryable,
                        $retryable ? 'nack' : 'reject',
                        null,
                        $because,
                    ),
                );
                $this->handle($envelope);
                if (!$envelope->isResponded()) {
                    $envelope->ack();
                }
                $result = $envelope->response();
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
            if ($envelope !== null && $envelope->isResponded()) {
                // The handler already answered the broker; a second answer would close the channel.
                $this->logger()->error('RabbitMQ handler failed after responding to the message.', [
                    'worker' => static::class,
                    'queue' => $queue,
                    'response' => $envelope->response(),
                    'exception' => $exception,
                ]);

                return;
            }

            $this->fail($message, true, 'exception', $exception);
        } finally {
            $this->activeChannel = null;
            $this->activeErrorExchange = null;
            $this->activeQueue = null;
            $this->activeRetry = [];
        }
    }

    /**
     * Routes a failed message: retryable failures go to the retry queue until
     * max_attempts is reached; everything else is parked in the error queue
     * with headers explaining why.
     */
    private function fail(AMQPMessage $message, bool $retryable, string $reason, ?\Throwable $exception = null, string $detail = ''): void
    {
        $queue = (string) $this->activeQueue;
        $attempt = $this->attempts($message, $queue);
        $maxAttempts = (int) ($this->activeRetry['max_attempts'] ?? 1);

        if ($retryable && $attempt < $maxAttempts) {
            $message->nack(false, false);
            $this->logger()->warning('RabbitMQ message sent to retry.', [
                'worker' => static::class,
                'queue' => $queue,
                'attempt' => $attempt,
                'exception' => $exception,
            ]);
            $this->recordTelemetry('message_retried', [
                'worker' => static::class,
                'queue' => $queue,
                'attempt' => $attempt,
            ]);

            return;
        }

        $this->activeChannel->basic_publish(
            $this->withFailureHeaders($message, $reason, $attempt, $exception, $detail),
            (string) $this->activeErrorExchange,
            $queue,
        );
        $message->ack();
        $this->logger()->error('RabbitMQ message sent to the error queue.', [
            'worker' => static::class,
            'queue' => $queue,
            'reason' => $reason,
            'attempt' => $attempt,
            'exception' => $exception,
        ]);
        $this->recordTelemetry('message_sent_to_error_queue', [
            'worker' => static::class,
            'queue' => $queue,
            'reason' => $reason,
            'attempt' => $attempt,
            'error_exchange' => $this->activeErrorExchange,
        ]);
    }

    private function withFailureHeaders(AMQPMessage $message, string $reason, int $attempt, ?\Throwable $exception, string $detail = ''): AMQPMessage
    {
        $properties = $message->get_properties();
        $headers = isset($properties['application_headers']) && method_exists($properties['application_headers'], 'getNativeData')
            ? $properties['application_headers']->getNativeData()
            : [];
        $headers['x-mirabel-failure-reason'] = $reason;
        $headers['x-mirabel-attempts'] = $attempt;
        $headers['x-mirabel-failed-at'] = time();
        if ($exception !== null) {
            $headers['x-mirabel-exception'] = $exception::class . ': ' . substr($exception->getMessage(), 0, 500);
        }
        if ($detail !== '') {
            $headers['x-mirabel-detail'] = substr($detail, 0, 500);
        }
        $properties['application_headers'] = new AMQPTable($headers);

        return new AMQPMessage($message->getBody(), $properties);
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

    private function recordTelemetry(string $event, array $context = []): void
    {
        TelemetryRuntime::record($event, $context, $this->logger());
    }

    /**
     * What this worker will declare and consume, after the constants,
     * the static properties and the conventions are resolved.
     *
     * @return array{queue: string, routing_keys: list<string>, retry: array{delay: int, max_attempts: int}, options: array<string, mixed>}
     */
    public static function definition(): array
    {
        return [
            'queue' => Convention::queue(static::class),
            'routing_keys' => Convention::routingKeys(static::class),
            'retry' => Convention::retry(static::class),
            'options' => Convention::options(static::class),
        ];
    }

    private function queueName(): string
    {
        return Convention::queue(static::class);
    }

    private function routingKeys(): array
    {
        return Convention::routingKeys(static::class);
    }

    private function options(): array
    {
        return Convention::options(static::class);
    }

    private function retrySettings(): array
    {
        return Convention::retry(static::class);
    }
}
