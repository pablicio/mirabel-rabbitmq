<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq\Traits;

use Pablicio\MirabelRabbitmq\Facades\RabbitMQ;

/**
 * Trait para facilitar publicação de eventos
 * Uso em classes de eventos do Laravel
 */
trait RabbitMQEventsConnection
{
    protected string $routingKey;
    protected mixed $payload;
    protected ?string $connection = null;
    protected ?string $exchange = null;
    protected array $options = [];

    /**
     * Publica o evento
     */
    public function publish(): bool
    {
        return RabbitMQ::publishEvent(
            $this->payload,
            $this->routingKey,
            $this->exchange,
            $this->connection,
            $this->options
        );
    }

    /**
     * Publica com prioridade alta
     */
    public function publishUrgent(): bool
    {
        $this->options = array_merge($this->options, ['priority' => 9]);
        return $this->publish();
    }

    /**
     * Publica com delay
     */
    public function publishDelayed(int $delayMs): bool
    {
        $this->options = array_merge($this->options, ['expiration' => (string)$delayMs]);
        return $this->publish();
    }

    /**
     * Define a conexão
     */
    public function onConnection(string $connection): self
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * Define o exchange
     */
    public function toExchange(string $exchange): self
    {
        $this->exchange = $exchange;
        return $this;
    }

    /**
     * Define opções customizadas
     */
    public function withOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }
}
