<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Tests\Integration;

use Mirabel\RabbitMQ\Envelope;
use Mirabel\RabbitMQ\Event;
use Mirabel\RabbitMQ\Worker;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real Worker against a real broker. Enable with
 * MIRABEL_RABBITMQ_INTEGRATION=1 (see docker-compose.yml).
 */
final class WorkerTopologyTest extends TestCase
{
    private ?AMQPStreamConnection $connection = null;
    private ?AMQPChannel $channel = null;
    private string $exchange;
    /** @var list<string> */
    private array $queues = [];
    /** @var list<string> */
    private array $exchanges = [];

    protected function setUp(): void
    {
        if (getenv('MIRABEL_RABBITMQ_INTEGRATION') !== '1') {
            self::markTestSkipped('Set MIRABEL_RABBITMQ_INTEGRATION=1 to run RabbitMQ integration tests.');
        }

        $this->exchange = 'mirabel.it.' . bin2hex(random_bytes(4));
        putenv('MB_RABBITMQ_EXCHANGE=' . $this->exchange);
        putenv('MB_RABBITMQ_PUBLISHER_CONFIRMS=true');

        $this->connection = new AMQPStreamConnection(
            getenv('MB_RABBITMQ_HOST') ?: '127.0.0.1',
            (int) (getenv('MB_RABBITMQ_PORT') ?: 5672),
            getenv('MB_RABBITMQ_USER') ?: 'guest',
            getenv('MB_RABBITMQ_PASSWORD') ?: 'guest',
            getenv('MB_RABBITMQ_VHOST') ?: '/',
        );
        $this->channel = $this->connection->channel();
        $this->exchanges[] = $this->exchange;
    }

    protected function tearDown(): void
    {
        if ($this->channel !== null) {
            foreach ($this->queues as $queue) {
                $this->channel->queue_delete($queue);
            }
            foreach ($this->exchanges as $exchange) {
                $this->channel->exchange_delete($exchange);
            }
            $this->channel->close();
        }
        $this->connection?->close();
        putenv('MB_RABBITMQ_EXCHANGE');
        putenv('MB_RABBITMQ_PUBLISHER_CONFIRMS');
    }

    public function testHandledMessageIsAcknowledgedAndLeavesTheQueueEmpty(): void
    {
        $worker = new StoppingWorker($this->exchange . '.happy', ['order.paid'], static function (Envelope $envelope, StoppingWorker $worker): void {
            $worker->stop();
        });
        $this->track($worker::$queue);
        $worker->declare();

        (new PaidEvent(['order' => 1]))->publish();
        $worker->subscribe();

        self::assertSame([['order' => 1]], $worker->bodies);
        self::assertSame(0, $this->messagesIn($worker::$queue));
    }

    public function testRetriedMessageReturnsOnlyToItsOwnQueue(): void
    {
        $worker = new StoppingWorker($this->exchange . '.flaky', ['order.paid'], static function (Envelope $envelope, StoppingWorker $worker): void {
            if ($envelope->attempt === 2) {
                $worker->stop();
            }
            throw new \RuntimeException('gateway timeout on attempt ' . $envelope->attempt);
        }, maxAttempts: 2);
        $this->track($worker::$queue);
        $worker->declare();

        // Another service listening to the same routing key.
        $neighbour = $this->exchange . '.neighbour';
        $this->channel->queue_declare($neighbour, false, true, false, false);
        $this->channel->queue_bind($neighbour, $this->exchange, 'order.paid');
        $this->queues[] = $neighbour;

        (new PaidEvent(['order' => 2]))->publish();
        $worker->subscribe();

        self::assertSame([1, 2], $worker->attempts);
        self::assertSame(1, $this->messagesIn($neighbour), 'The retry must not be delivered to other queues bound to the same key.');
        self::assertSame(1, $this->messagesIn($worker::$queue . '.error'));

        $parked = $this->channel->basic_get($worker::$queue . '.error', true);
        $headers = $parked->get('application_headers')->getNativeData();
        self::assertSame('exception', $headers['x-mirabel-failure-reason']);
        self::assertSame(2, $headers['x-mirabel-attempts']);
        self::assertStringContainsString('gateway timeout on attempt 2', $headers['x-mirabel-exception']);
    }

    public function testPoisonMessageIsParkedWithoutReachingTheHandler(): void
    {
        $worker = new StoppingWorker($this->exchange . '.poison', ['order.paid'], static function (): void {
            throw new \LogicException('handler must not run');
        });
        $this->track($worker::$queue);
        $worker->declare();

        $this->channel->basic_publish(new AMQPMessage('{not json'), $this->exchange, 'order.paid');
        $worker->stopAfterIdle = true;
        $worker->subscribe();

        self::assertSame([], $worker->bodies);
        self::assertSame(1, $this->messagesIn($worker::$queue . '.error'));
    }

    public function testEmptyRetryQueueFromAnOlderVersionIsRecreated(): void
    {
        $worker = new StoppingWorker($this->exchange . '.legacy', ['order.paid'], static function (): void {
        });
        $this->track($worker::$queue);
        $this->declareLegacyRetryQueue($worker::$queue);

        $worker->declare();

        self::assertSame(0, $this->messagesIn($worker::$queue . '.retry'));
    }

    public function testRetryQueueFromAnOlderVersionWithMessagesIsKept(): void
    {
        $worker = new StoppingWorker($this->exchange . '.legacy-busy', ['order.paid'], static function (): void {
        });
        $this->track($worker::$queue);
        $this->declareLegacyRetryQueue($worker::$queue);
        $this->channel->basic_publish(new AMQPMessage('{"waiting":true}'), '', $worker::$queue . '.retry');

        try {
            $worker->declare();
            self::fail('A retry queue holding messages must not be deleted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('still holds messages', $exception->getMessage());
        }

        self::assertSame(1, $this->messagesIn($worker::$queue . '.retry'));
    }

    public function testQuorumQueueWorkerRetriesAndSucceeds(): void
    {
        $worker = new StoppingWorker($this->exchange . '.quorum', ['order.paid'], static function (Envelope $envelope, StoppingWorker $worker): void {
            if ($envelope->attempt === 1) {
                throw new \RuntimeException('first attempt fails');
            }
            $worker->stop();
        }, maxAttempts: 3, queueType: 'quorum');
        $this->track($worker::$queue);
        $worker->declare();

        (new PaidEvent(['order' => 7]))->publish();
        $worker->subscribe();

        self::assertSame([1, 2], $worker->attempts);
        $queues = $this->queueTypes();
        self::assertSame('quorum', $queues[$worker::$queue]);
        self::assertSame('quorum', $queues[$worker::$queue . '.retry']);
    }

    public function testWorkerStopsInsteadOfRetryingWhenTheQueueHasAnotherType(): void
    {
        $queue = $this->exchange . '.mismatch';
        $this->channel->queue_declare($queue, false, true, false, false, false, [
            'x-queue-type' => ['S', 'quorum'],
        ]);
        $this->track($queue);
        $worker = new StoppingWorker($queue, ['order.paid'], static function (): void {
        });

        $this->expectException(\PhpAmqpLib\Exception\AMQPProtocolChannelException::class);
        $this->expectExceptionMessage('PRECONDITION_FAILED');
        $worker->subscribe();
    }

    /** @return array<string, string> */
    private function queueTypes(): array
    {
        $api = getenv('MB_RABBITMQ_MANAGEMENT_URL') ?: 'http://127.0.0.1:15672';
        $context = stream_context_create(['http' => [
            'header' => 'Authorization: Basic ' . base64_encode('guest:guest'),
        ]]);
        $queues = json_decode((string) file_get_contents($api . '/api/queues/%2F?columns=name,type', false, $context), true);

        return array_column($queues, 'type', 'name');
    }

    private function declareLegacyRetryQueue(string $queue): void
    {
        $this->channel->queue_declare($queue . '.retry', false, true, false, false, false, [
            'x-message-ttl' => ['I', 60000],
            'x-dead-letter-exchange' => ['S', $this->exchange],
            'x-dead-letter-routing-key' => ['S', 'order.paid'],
        ]);
    }

    private function track(string $queue): void
    {
        array_push($this->queues, $queue, $queue . '.retry', $queue . '.error');
        array_push($this->exchanges, $queue . '.retry', $queue . '.error');
    }

    private function messagesIn(string $queue): int
    {
        [, $messages] = $this->channel->queue_declare($queue, true);

        return (int) $messages;
    }
}

final class PaidEvent extends Event
{
    public static string $routingKey = 'order.paid';
}

final class StoppingWorker extends Worker
{
    public static string $queue;
    public static array $routingKeys = [];
    public static array $retry = [];

    public array $bodies = [];
    public array $attempts = [];
    public bool $stopAfterIdle = false;

    public function __construct(
        string $queue,
        array $routingKeys,
        private readonly \Closure $onMessage,
        int $maxAttempts = 1,
        ?string $queueType = null,
    ) {
        self::$queue = $queue;
        self::$routingKeys = $routingKeys;
        self::$retry = ['delay' => 200, 'max_attempts' => $maxAttempts];
        self::$options = $queueType === null ? [] : ['queue_type' => $queueType];
    }

    /** Declares the topology up front so a publish before subscribe() is not lost. */
    public function declare(): void
    {
        $config = \Mirabel\RabbitMQ\ConnectionConfig::fromEnvironment();
        $connection = $this->connectionFactory()->connect($config);
        $channel = $connection->channel();
        (new \ReflectionMethod(Worker::class, 'declareTopology'))->invoke(
            $this, $channel, $config, self::$queue, self::$routingKeys, 'topic', self::$retry,
            self::$options['queue_type'] ?? null,
        );
        $channel->close();
        $connection->close();
    }

    public function handle(Envelope $envelope): void
    {
        $this->bodies[] = $envelope->body;
        $this->attempts[] = $envelope->attempt;
        ($this->onMessage)($envelope, $this);
    }

    protected function logger(): \Psr\Log\LoggerInterface
    {
        $worker = $this;

        // Stops the poison test once the parked message has been handled.
        return new class ($worker) extends \Psr\Log\AbstractLogger {
            public function __construct(private readonly StoppingWorker $worker)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ($this->worker->stopAfterIdle && str_contains((string) $message, 'error queue')) {
                    $this->worker->stop();
                }
            }
        };
    }
}
