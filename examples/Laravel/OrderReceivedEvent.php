<?php

namespace App\Events;

use Pablicio\MirabelRabbitmq\Traits\RabbitMQEventsConnection;

/**
 * Evento de pedido recebido
 * Exemplo de uso simples sem conhecer detalhes da implementação
 */
class OrderReceivedEvent
{
    use RabbitMQEventsConnection;

    const ROUTING_KEY = 'orders.received';

    public function __construct($orderData)
    {
        $this->routingKey = self::ROUTING_KEY;
        $this->payload = $orderData;
    }
}

// ═══════════════════════════════════════════════════════
// COMO USAR
// ═══════════════════════════════════════════════════════

// 1. Uso básico
// (new OrderReceivedEvent($orderData))->publish();

// 2. Com prioridade
// (new OrderReceivedEvent($orderData))->publishUrgent();

// 3. Com delay
// (new OrderReceivedEvent($orderData))->publishDelayed(5000); // 5 segundos

// 4. Em conexão específica
// (new OrderReceivedEvent($orderData))
//     ->onConnection('rabbitmq-secondary')
//     ->publish();

// 5. Com opções customizadas
// (new OrderReceivedEvent($orderData))
//     ->withOptions(['priority' => 7, 'expiration' => '60000'])
//     ->publish();
