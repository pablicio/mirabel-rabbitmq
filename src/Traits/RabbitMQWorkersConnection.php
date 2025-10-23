<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq\Traits;

use Pablicio\MirabelRabbitmq\Facades\RabbitMQ;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Trait para facilitar consumo de mensagens
 * Uso em classes de workers/consumers do Laravel
 */
trait RabbitMQWorkersConnection
{
    protected string $queue;
    protected array $routingKeys = [];
    protected array $options = [];
    protected array $retryOptions = [];
    protected ?string $connection = null;

    /**
     * Inicia o consumo de mensagens
     */
    public function subscribe(): void
    {
        RabbitMQ::subscribe(
            $this->queue ?? static::QUEUE,
            [$this, 'work'],
            $this->routingKeys ?? static::ROUTING_KEYS ?? [],
            $this->options ?? static::OPTIONS ?? [],
            $this->retryOptions ?? static::RETRY_OPTIONS ?? [],
            $this->connection
        );
    }

    /**
     * Método abstrato que deve ser implementado pela classe
     * @param AMQPMessage $msg
     * @return bool
     */
    abstract public function work($msg);

    /**
     * ACK - confirma processamento da mensagem
     */
    protected function ack(AMQPMessage $msg): bool
    {
        $msg->ack();
        return true;
    }

    /**
     * NACK - rejeita mensagem (vai para retry ou DLQ)
     */
    protected function nack(AMQPMessage $msg, bool $requeue = false): bool
    {
        $msg->nack($requeue);
        return false;
    }

    /**
     * REJECT - rejeita mensagem completamente
     */
    protected function reject(AMQPMessage $msg, bool $requeue = false): bool
    {
        $msg->reject($requeue);
        return false;
    }

    /**
     * Obtém o payload deserializado
     */
    protected function getPayload(AMQPMessage $msg): mixed
    {
        return json_decode($msg->getBody(), true);
    }

    /**
     * Define a fila
     */
    public function setQueue(string $queue): self
    {
        $this->queue = $queue;
        return $this;
    }

    /**
     * Define as routing keys
     */
    public function setRoutingKeys(array $routingKeys): self
    {
        $this->routingKeys = $routingKeys;
        return $this;
    }

    /**
     * Define opções
     */
    public function setOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }

    /**
     * Define opções de retry
     */
    public function setRetryOptions(array $retryOptions): self
    {
        $this->retryOptions = $retryOptions;
        return $this;
    }

    /**
     * Define a conexão
     */
    public function onConnection(string $connection): self
    {
        $this->connection = $connection;
        return $this;
    }
}
