<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Tests;

use Mirabel\RabbitMQ\ConnectionConfig;
use Mirabel\RabbitMQ\Envelope;
use Mirabel\RabbitMQ\Worker;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class WorkerEnvelopeTest extends TestCase
{
    public function testHandlerThatReturnsNormallyIsAcknowledgedAutomatically(): void
    {
        $channel = $this->channel();
        $channel->expects(self::once())->method('basic_ack')->with(1, false);
        $channel->expects(self::never())->method('basic_nack');
        $channel->expects(self::never())->method('basic_publish');

        $worker = new EnvelopeWorkerForTest(static function (Envelope $envelope): void {
        });
        $this->process($worker, $channel, $this->message($channel, '{"id":1}', 1));

        self::assertSame(['id' => 1], $worker->received[0]->body);
        self::assertSame(1, $worker->received[0]->attempt);
    }

    public function testExceptionOnFirstAttemptGoesToRetryQueue(): void
    {
        $channel = $this->channel();
        $channel->expects(self::once())->method('basic_nack')->with(2, false, false);
        $channel->expects(self::never())->method('basic_publish');

        $worker = new EnvelopeWorkerForTest(static function (): void {
            throw new \RuntimeException('payment gateway timeout');
        });
        $this->process($worker, $channel, $this->message($channel, '{"id":2}', 2));
    }

    public function testExceptionOnLastAttemptIsParkedWithFailureHeaders(): void
    {
        $channel = $this->channel();
        $channel->expects(self::once())
            ->method('basic_publish')
            ->with(self::callback(static function (AMQPMessage $message): bool {
                $headers = $message->get('application_headers')->getNativeData();

                return $message->getBody() === '{"id":3}'
                    && $headers['x-mirabel-failure-reason'] === 'exception'
                    && $headers['x-mirabel-attempts'] === 3
                    && str_contains($headers['x-mirabel-exception'], 'payment gateway timeout');
            }), 'orders.error', 'orders');
        $channel->expects(self::once())->method('basic_ack')->with(3, false);
        $channel->expects(self::never())->method('basic_nack');

        $worker = new EnvelopeWorkerForTest(static function (): void {
            throw new \RuntimeException('payment gateway timeout');
        });
        $this->process($worker, $channel, $this->message($channel, '{"id":3}', 3, retries: 2));
    }

    public function testRejectParksTheMessageWithoutSpendingRetries(): void
    {
        $channel = $this->channel();
        $channel->expects(self::once())->method('basic_publish')->with(self::anything(), 'orders.error', 'orders');
        $channel->expects(self::once())->method('basic_ack')->with(4, false);
        $channel->expects(self::never())->method('basic_nack');

        $worker = new EnvelopeWorkerForTest(static function (Envelope $envelope): void {
            $envelope->reject();
        });
        $this->process($worker, $channel, $this->message($channel, '{"id":4}', 4));
    }

    public function testManualNackOnLastAttemptDoesNotLoopThroughRetryForever(): void
    {
        $channel = $this->channel();
        $channel->expects(self::once())->method('basic_publish')->with(self::anything(), 'orders.error', 'orders');
        $channel->expects(self::once())->method('basic_ack')->with(5, false);
        $channel->expects(self::never())->method('basic_nack');

        $worker = new EnvelopeWorkerForTest(static function (Envelope $envelope): void {
            $envelope->nack();
        });
        $this->process($worker, $channel, $this->message($channel, '{"id":5}', 5, retries: 2));
    }

    public function testPoisonMessageIsParkedImmediately(): void
    {
        $channel = $this->channel();
        $channel->expects(self::once())
            ->method('basic_publish')
            ->with(
                self::callback(static fn (AMQPMessage $message): bool => $message->get('application_headers')->getNativeData()['x-mirabel-failure-reason'] === 'poison'),
                'orders.error',
                'orders',
            );
        $channel->expects(self::once())->method('basic_ack')->with(6, false);

        $worker = new EnvelopeWorkerForTest(static function (): void {
            throw new \LogicException('Poison message reached the handler.');
        });
        $this->process($worker, $channel, $this->message($channel, 'not json', 6));

        self::assertSame([], $worker->received);
    }

    public function testFailureAfterAckDoesNotAnswerTheBrokerTwice(): void
    {
        $channel = $this->channel();
        $channel->expects(self::once())->method('basic_ack')->with(7, false);
        $channel->expects(self::never())->method('basic_nack');
        $channel->expects(self::never())->method('basic_publish');

        $worker = new EnvelopeWorkerForTest(static function (Envelope $envelope): void {
            $envelope->ack();
            throw new \RuntimeException('audit log unavailable');
        });
        $this->process($worker, $channel, $this->message($channel, '{"id":7}', 7));
    }

    private function channel(): AMQPChannel
    {
        return $this->getMockBuilder(AMQPChannel::class)->disableOriginalConstructor()->getMock();
    }

    private function message(AMQPChannel $channel, string $body, int $deliveryTag, int $retries = 0): AMQPMessage
    {
        $properties = [];
        if ($retries > 0) {
            $properties['application_headers'] = new AMQPTable([
                'x-death' => [['queue' => 'orders.retry', 'count' => $retries]],
            ]);
        }

        $message = new AMQPMessage($body, $properties);
        $message->setChannel($channel);
        $message->setDeliveryTag($deliveryTag);

        return $message;
    }

    private function process(Worker $worker, AMQPChannel $channel, AMQPMessage $message): void
    {
        (new ReflectionMethod(Worker::class, 'process'))->invoke(
            $worker,
            $channel,
            new ConnectionConfig('localhost', 5672, 'guest', 'guest'),
            'orders.error',
            $message,
            ['delay' => 1000, 'max_attempts' => 3],
            'orders',
        );
    }
}

final class EnvelopeWorkerForTest extends Worker
{
    public static string $queue = 'orders';

    /** @var list<Envelope> */
    public array $received = [];

    public function __construct(private readonly \Closure $handler)
    {
    }

    public function handle(Envelope $envelope): void
    {
        $this->received[] = $envelope;
        ($this->handler)($envelope);
    }
}
