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
        putenv('MB_RABBITMQ_PUBLISH_RETRIES');
    }

    public function testFullQueueRefusesThePublishWhenItRejectsOverflow(): void
    {
        putenv('MB_RABBITMQ_PUBLISH_RETRIES=0');
        $worker = new CappedWorker($this->exchange . '.capped');
        $this->track($worker::$queue);
        $worker->declare();

        (new PaidEvent(['order' => 1]))->publish();
        (new PaidEvent(['order' => 2]))->publish();

        $this->expectException(\Mirabel\RabbitMQ\Exception\PublishNotConfirmedException::class);
        try {
            (new PaidEvent(['order' => 3]))->publish();
        } finally {
            self::assertSame(2, $worker->pending());
        }
    }

    public function testPriorityQueueDeliversTheUrgentFirst(): void
    {
        $worker = new PriorityWorker($this->exchange . '.priority');
        $this->track($worker::$queue);
        $worker->declare();

        foreach ([[0, 1], [1, 1], [2, 5], [3, 1], [4, 5]] as [$order, $priority]) {
            (new PaidEvent(['order' => $order]))->publish(priority: $priority);
        }

        $peeked = $worker->peek(5);
        self::assertSame([2, 4, 0, 1, 3], array_map(static fn (Envelope $e) => $e->body['order'], $peeked));
        self::assertSame([5, 5, 1, 1, 1], array_map(static fn (Envelope $e) => $e->priority, $peeked));
    }

    public function testSingleActiveConsumerQueueIsDeclared(): void
    {
        $worker = new SoloWorker($this->exchange . '.solo');
        $this->track($worker::$queue);
        $worker->declare();
        // Declaring it again with the same arguments is accepted.
        $worker->declare();

        self::assertSame(['x-single-active-consumer' => true], array_intersect_key(
            $this->argumentsOf($worker::$queue),
            ['x-single-active-consumer' => true],
        ));
    }

    public function testMandatoryEventWithoutAQueueComesBack(): void
    {
        putenv('MB_RABBITMQ_PUBLISHER_CONFIRMS=false');
        $started = microtime(true);

        try {
            (new MandatoryEvent(['order' => 1]))->publish();
            self::fail('An unroutable mandatory event must throw.');
        } catch (\Mirabel\RabbitMQ\Exception\UnroutableException $exception) {
            self::assertStringContainsString('312 NO_ROUTE', $exception->getMessage());
            self::assertStringContainsString('order.nobody.listens', $exception->getMessage());
        }
        self::assertLessThan(1.0, microtime(true) - $started, 'An unroutable event is not retried.');
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

    public function testStatsComeFromTheManagementApi(): void
    {
        if (getenv('MB_RABBITMQ_MANAGEMENT_URL') === false) {
            self::markTestSkipped('Set MB_RABBITMQ_MANAGEMENT_URL to reach the management API.');
        }
        $worker = new StoppingWorker($this->exchange . '.stats', ['order.paid'], static function (): void {
        });
        $this->track($worker::$queue);
        $worker->declare();
        (new PaidEvent(['order' => 1]))->publish();
        (new PaidEvent(['order' => 2]))->publish();

        $stats = null;
        for ($i = 0; $i < 30 && ($stats?->ready !== 2 || $stats->oldestWaitSeconds === null); $i++) {
            usleep(500_000); // the API refreshes its numbers every few seconds
            $stats = $worker->stats();
        }

        self::assertSame($worker::$queue, $stats->queue);
        self::assertSame(2, $stats->ready);
        self::assertSame(0, $stats->consumers);
        self::assertNotNull($stats->oldestWaitSeconds, 'Mirabel stamps every message with a timestamp.');
        self::assertLessThan(60, $stats->oldestWaitSeconds);
    }

    public function testPendingCountsTheMessagesWaitingInTheQueue(): void
    {
        $worker = new StoppingWorker($this->exchange . '.pending', ['order.paid'], static function (): void {
        });
        $this->track($worker::$queue);
        self::assertSame(0, $worker->pending(), 'A queue that was never declared has nothing pending.');

        $worker->declare();
        (new PaidEvent(['order' => 1]))->publish();
        (new PaidEvent(['order' => 2]))->publish();

        self::assertSame(2, $worker->pending());
        self::assertSame([
            $worker::$queue => 2,
            $worker::$queue . '.retry' => 0,
            $worker::$queue . '.error' => 0,
        ], $worker->counts());

        self::assertSame(2, $worker->purge());
        self::assertSame(0, $worker->pending());
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

    public function testFailedMessagesCanBeReadAndReprocessedWithFreshAttempts(): void
    {
        $worker = new StoppingWorker($this->exchange . '.reprocess', ['order.paid'], static function (Envelope $envelope, StoppingWorker $worker): void {
            if ($envelope->attempt === 2) {
                $worker->stop();
            }
            throw new \RuntimeException('SEFAZ offline');
        }, maxAttempts: 2);
        $this->track($worker::$queue);
        $worker->declare();
        (new PaidEvent(['order' => 7]))->publish(messageId: 'order-7-paid');
        $worker->subscribe();

        $failed = $worker->failed();
        self::assertCount(1, $failed);
        self::assertSame(['order' => 7], $failed[0]->body);
        self::assertSame('order-7-paid', $failed[0]->messageId);
        self::assertSame('exception', $failed[0]->failure()['reason']);
        self::assertSame(2, $failed[0]->failure()['attempts']);
        self::assertStringContainsString('SEFAZ offline', (string) $failed[0]->failure()['exception']);
        self::assertSame(1, $this->messagesIn($worker::$queue . '.error'), 'failed() must not remove anything.');

        self::assertSame(1, $worker->reprocess());
        self::assertSame(0, $this->messagesIn($worker::$queue . '.error'));

        $back = $worker->peek();
        self::assertCount(1, $back);
        self::assertSame('order-7-paid', $back[0]->messageId);
        self::assertSame(1, $back[0]->attempt, 'A reprocessed message starts its attempts over.');
        self::assertNull($back[0]->failure());
        self::assertSame(1, $worker->pending(), 'peek() must not remove anything.');
        self::assertSame('order-7-paid', $back[0]->toArray()['message_id']);
    }

    public function testErrorQueueIsSweptByReason(): void
    {
        $worker = new StoppingWorker($this->exchange . '.sweep', ['order.paid'], static function (Envelope $envelope, StoppingWorker $worker): void {
            $order = $envelope->body['order'];
            if ($order === 3) {
                $worker->stop();
            }
            $envelope->reject(because: $order === 2 ? 'unknown zip code range' : 'test order');
        });
        $this->track($worker::$queue);
        $worker->declare();
        foreach ([1, 2, 3] as $order) {
            (new PaidEvent(['order' => $order]))->publish();
        }
        $worker->subscribe();

        $details = array_map(static fn (Envelope $e): ?string => $e->failure()['detail'], $worker->failed());
        self::assertSame(['test order', 'unknown zip code range', 'test order'], $details);

        $zip = static fn (Envelope $e): bool => $e->failure()['detail'] === 'unknown zip code range';
        self::assertSame(1, $worker->reprocess($zip));
        self::assertSame(1, $worker->pending());
        self::assertSame(2, $this->messagesIn($worker::$queue . '.error'), 'The others stay in the error queue.');

        self::assertSame(2, $worker->discard(static fn (Envelope $e): bool => $e->failure()['detail'] === 'test order'));
        self::assertSame(0, $this->messagesIn($worker::$queue . '.error'));
        self::assertSame([2], array_map(static fn (Envelope $e) => $e->body['order'], $worker->peek(10)));
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

        $this->expectException(\Mirabel\RabbitMQ\Exception\TopologyException::class);
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

    /** @return array<string, mixed> */
    private function argumentsOf(string $queue): array
    {
        $url = rtrim((string) getenv('MB_RABBITMQ_MANAGEMENT_URL'), '/') . '/api/queues/%2F/' . rawurlencode($queue);
        $context = stream_context_create(['http' => ['header' => 'Authorization: Basic ' . base64_encode('guest:guest')]]);

        return json_decode((string) file_get_contents($url, false, $context), true)['arguments'] ?? [];
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

final class CappedWorker extends Worker
{
    public static string $queue;

    const EVENTS = [PaidEvent::class],
        MAX_LENGTH = 2,
        OVERFLOW = 'reject-publish';

    public function __construct(string $queue)
    {
        self::$queue = $queue;
    }
}

final class MandatoryEvent extends Event
{
    const ROUTING_KEY = 'order.nobody.listens',
        MANDATORY = true;
}

final class PriorityWorker extends Worker
{
    public static string $queue;

    const EVENTS = [PaidEvent::class],
        MAX_PRIORITY = 10;

    public function __construct(string $queue)
    {
        self::$queue = $queue;
    }
}

final class SoloWorker extends Worker
{
    public static string $queue;

    const EVENTS = [PaidEvent::class],
        SINGLE_ACTIVE_CONSUMER = true;

    public function __construct(string $queue)
    {
        self::$queue = $queue;
    }
}
