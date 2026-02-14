<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq\Consumers;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Pablicio\MirabelRabbitmq\Connection\ChannelPool;
use Pablicio\MirabelRabbitmq\Contracts\SerializerInterface;
use Pablicio\MirabelRabbitmq\Exceptions\RabbitMQException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Consumer
{
    private ChannelPool $channelPool;
    private SerializerInterface $serializer;
    private LoggerInterface $logger;
    private bool $shouldStop = false;
    private array $stats = [
        'messages_processed' => 0,
        'messages_acked' => 0,
        'messages_nacked' => 0,
        'messages_rejected' => 0,
        'errors' => 0,
    ];

    public function __construct(
        ChannelPool $channelPool,
        SerializerInterface $serializer,
        ?LoggerInterface $logger = null
    ) {
        $this->channelPool = $channelPool;
        $this->serializer = $serializer;
        $this->logger = $logger ?? new NullLogger();

        // Configura handlers de sinais para graceful shutdown
        $this->setupSignalHandlers();
    }

    /**
     * Consome mensagens de uma fila
     */
    public function consume(
        string $queue,
        callable $callback,
        array $options = []
    ): void {
        $channel = $this->channelPool->getChannel('consumer_' . $queue);

        // Configura QoS
        $prefetchCount = $options['prefetch_count'] ?? 1;
        $channel->basic_qos(0, $prefetchCount, false);

        $this->logger->info('Starting consumer', [
            'queue' => $queue,
            'prefetch_count' => $prefetchCount,
        ]);

        // Define o consumer
        $consumerTag = $options['consumer_tag'] ?? 'consumer_' . uniqid();
        
        $channel->basic_consume(
            $queue,
            $consumerTag,
            $options['no_local'] ?? false,
            $options['no_ack'] ?? false,
            $options['exclusive'] ?? false,
            $options['nowait'] ?? false,
            function (AMQPMessage $message) use ($callback, $options, $queue) {
                $this->handleMessage($message, $callback, $options, $queue);
            }
        );

        // Loop de consumo
        while ($channel->is_consuming() && !$this->shouldStop) {
            try {
                $channel->wait(null, false, $options['timeout'] ?? 0);
            } catch (\Exception $e) {
                $this->logger->error('Error in consumer loop', [
                    'queue' => $queue,
                    'error' => $e->getMessage(),
                ]);
                
                if ($options['stop_on_error'] ?? false) {
                    break;
                }
            }
        }

        $this->logger->info('Consumer stopped', [
            'queue' => $queue,
            'stats' => $this->stats,
        ]);
    }

    /**
     * Processa uma mensagem individual
     */
    private function handleMessage(
        AMQPMessage $message,
        callable $callback,
        array $options,
        string $queue
    ): void {
        $this->stats['messages_processed']++;

        try {
            // Deserializa a mensagem
            $data = $this->serializer->deserialize($message->getBody());

            // Log da mensagem recebida
            $this->logger->debug('Message received', [
                'queue' => $queue,
                'delivery_tag' => $message->getDeliveryTag(),
            ]);

            // Executa callback com retry
            $result = $this->executeWithRetry($callback, $data, $message, $options);

            // Processa resultado
            if ($result === true || $result === null) {
                $this->acknowledgeMessage($message);
            } elseif ($result === false) {
                $this->rejectMessage($message, $options['requeue'] ?? false);
            } else {
                // Resultado customizado
                $this->handleCustomResult($message, $result, $options);
            }

        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->error('Error processing message', [
                'queue' => $queue,
                'error' => $e->getMessage(),
                'delivery_tag' => $message->getDeliveryTag(),
            ]);

            $this->handleMessageError($message, $e, $options);
        }
    }

    /**
     * Executa callback com retry
     */
    private function executeWithRetry(
        callable $callback,
        mixed $data,
        AMQPMessage $message,
        array $options
    ): mixed {
        $maxRetries = $options['max_retries'] ?? 0;
        $retryDelay = $options['retry_delay'] ?? 0;
        $attempt = 0;

        while ($attempt <= $maxRetries) {
            try {
                return $callback($data, $message, $attempt);
            } catch (\Exception $e) {
                $attempt++;
                
                if ($attempt > $maxRetries) {
                    throw $e;
                }

                $this->logger->warning('Retry attempt', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage(),
                ]);

                if ($retryDelay > 0) {
                    usleep($retryDelay * 1000); // Converte para microsegundos
                }
            }
        }

        return false;
    }

    /**
     * Reconhece mensagem (ACK)
     */
    private function acknowledgeMessage(AMQPMessage $message): void
    {
        $message->ack();
        $this->stats['messages_acked']++;
        
        $this->logger->debug('Message acknowledged', [
            'delivery_tag' => $message->getDeliveryTag(),
        ]);
    }

    /**
     * Rejeita mensagem (NACK)
     */
    private function rejectMessage(AMQPMessage $message, bool $requeue): void
    {
        $message->nack($requeue);
        $this->stats['messages_nacked']++;
        
        $this->logger->debug('Message rejected', [
            'delivery_tag' => $message->getDeliveryTag(),
            'requeue' => $requeue,
        ]);
    }

    /**
     * Rejeita mensagem completamente (REJECT)
     */
    private function basicReject(AMQPMessage $message, bool $requeue): void
    {
        $message->reject($requeue);
        $this->stats['messages_rejected']++;
        
        $this->logger->debug('Message basic rejected', [
            'delivery_tag' => $message->getDeliveryTag(),
            'requeue' => $requeue,
        ]);
    }

    /**
     * Trata resultado customizado
     */
    private function handleCustomResult(AMQPMessage $message, mixed $result, array $options): void
    {
        if (is_array($result)) {
            $action = $result['action'] ?? 'ack';
            $requeue = $result['requeue'] ?? false;

            match ($action) {
                'ack' => $this->acknowledgeMessage($message),
                'nack' => $this->rejectMessage($message, $requeue),
                'reject' => $this->basicReject($message, $requeue),
                default => $this->acknowledgeMessage($message),
            };
        } else {
            $this->acknowledgeMessage($message);
        }
    }

    /**
     * Trata erro ao processar mensagem
     */
    private function handleMessageError(AMQPMessage $message, \Exception $error, array $options): void
    {
        $errorStrategy = $options['error_strategy'] ?? 'reject_to_dlq';

        match ($errorStrategy) {
            'reject_to_dlq' => $this->basicReject($message, false),
            'reject_and_requeue' => $this->basicReject($message, true),
            'nack_to_dlq' => $this->rejectMessage($message, false),
            'nack_and_requeue' => $this->rejectMessage($message, true),
            default => $this->basicReject($message, false),
        };
    }

    /**
     * Configura handlers de sinais para graceful shutdown
     */
    private function setupSignalHandlers(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleShutdownSignal']);
            pcntl_signal(SIGINT, [$this, 'handleShutdownSignal']);
            
            $this->logger->info('Signal handlers configured');
        }
    }

    /**
     * Handler para sinal de shutdown
     */
    public function handleShutdownSignal(int $signal): void
    {
        $this->logger->info('Shutdown signal received', ['signal' => $signal]);
        $this->shouldStop = true;
    }

    /**
     * Para o consumidor
     */
    public function stop(): void
    {
        $this->shouldStop = true;
        $this->logger->info('Consumer stop requested');
    }

    /**
     * Retorna estatísticas do consumer
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Reseta estatísticas
     */
    public function resetStats(): void
    {
        $this->stats = [
            'messages_processed' => 0,
            'messages_acked' => 0,
            'messages_nacked' => 0,
            'messages_rejected' => 0,
            'errors' => 0,
        ];
    }
}
