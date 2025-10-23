<?php

namespace App\Workers;

use Pablicio\MirabelRabbitmq\Traits\RabbitMQWorkersConnection;

/**
 * Worker para processar pedidos recebidos
 * Exemplo de uso simples sem conhecer detalhes da implementação
 */
class OrderReceivedWorker
{
    use RabbitMQWorkersConnection;

    const QUEUE = 'orders.processing';
    const ROUTING_KEYS = [
        'orders.received',
        'orders.updated'
    ];
    const OPTIONS = [
        'exchange_type' => 'topic'
    ];
    const RETRY_OPTIONS = [
        'x-message-ttl' => 5000,  // 5 segundos de delay entre retries
        'max-attempts' => 3        // 3 tentativas antes de ir para DLQ
    ];

    /**
     * Processa a mensagem recebida
     */
    public function work($msg)
    {
        try {
            // Obtém o payload deserializado
            $data = $this->getPayload($msg);
            
            \Log::info('Processing order', ['order_id' => $data['id'] ?? 'unknown']);
            
            // Sua lógica de negócio aqui
            $this->processOrder($data);
            
            // Sucesso - confirma processamento
            return $this->ack($msg);
            
        } catch (\Exception $e) {
            \Log::error('Error processing order', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Falha - rejeita para retry ou DLQ
            return $this->nack($msg);
        }
    }

    /**
     * Lógica de processamento do pedido
     */
    private function processOrder(array $data): void
    {
        // Simula processamento
        // Em produção, aqui você faria:
        // - Validações
        // - Salvamento no banco
        // - Envio de emails
        // - Integrações com outros sistemas
        // etc.
        
        sleep(1); // Simula processamento
    }
}

// ═══════════════════════════════════════════════════════
// COMO USAR
// ═══════════════════════════════════════════════════════

// 1. Uso básico
// (new OrderReceivedWorker)->subscribe();

// 2. Com conexão específica
// (new OrderReceivedWorker)
//     ->onConnection('rabbitmq-secondary')
//     ->subscribe();

// 3. Sobrescrevendo configurações
// (new OrderReceivedWorker)
//     ->setQueue('custom.queue')
//     ->setRoutingKeys(['custom.routing.key'])
//     ->setRetryOptions(['x-message-ttl' => 10000, 'max-attempts' => 5])
//     ->subscribe();
