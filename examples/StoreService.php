<?php

declare(strict_types=1);

namespace App\Services;

use Pablicio\MirabelRabbitmq\RabbitMQClient;
use Psr\Log\LoggerInterface;

/**
 * Serviço de gerenciamento de lojas
 * Consome eventos de produtos e publica eventos de lojas
 */
class StoreService
{
    public function __construct(
        private readonly RabbitMQClient $rabbitmq,
        private readonly LoggerInterface $logger
    ) {
        // Configura a estrutura de filas no construtor
        $this->setupQueues();
    }

    /**
     * Configura exchanges, queues e bindings
     */
    private function setupQueues(): void
    {
        // Exchange de eventos de lojas
        $this->rabbitmq->declareExchange(
            'stores.events',
            'topic',
            ['durable' => true]
        );

        // Fila para eventos de produtos (com DLQ)
        $this->rabbitmq->createQueueWithDLQ(
            'stores.product_events',
            [
                'durable' => true,
                'message_ttl' => 600000, // 10 minutos
            ]
        );

        // Bind para receber eventos de produtos criados e atualizados
        $this->rabbitmq->bindQueue(
            'stores.product_events',
            'products.events',
            'product.created'
        );

        $this->rabbitmq->bindQueue(
            'stores.product_events',
            'products.events',
            'product.stock.updated'
        );

        $this->rabbitmq->bindQueue(
            'stores.product_events',
            'products.events',
            'product.price.updated'
        );

        $this->rabbitmq->bindQueue(
            'stores.product_events',
            'products.events',
            'product.stock.low'
        );

        // Fila para comandos de atualização de estoque
        $this->rabbitmq->declareQueue(
            'products.update_stock',
            ['durable' => true]
        );

        $this->rabbitmq->bindQueue(
            'products.update_stock',
            'products.commands',
            'update.stock'
        );
    }

    /**
     * Cria uma nova loja
     */
    public function createStore(array $storeData): bool
    {
        $this->logger->info('Creating store', ['data' => $storeData]);

        // Simula salvamento no banco
        $store = [
            'id' => uniqid('store_'),
            'name' => $storeData['name'],
            'email' => $storeData['email'],
            'address' => $storeData['address'],
            'created_at' => date('Y-m-d H:i:s'),
        ];

        // Publica evento de loja criada
        $confirmed = $this->rabbitmq->publish(
            $store,
            'store.created',
            'stores.events',
            [
                'persistent' => true,
                'headers' => [
                    'event_type' => 'store.created',
                    'event_version' => '1.0',
                ],
            ]
        );

        if ($confirmed) {
            $this->logger->info('Store created event published', [
                'store_id' => $store['id'],
            ]);
            return true;
        }

        return false;
    }

    /**
     * Envia comando para atualizar estoque de produto
     */
    public function requestStockUpdate(
        string $productId,
        int $quantity,
        string $operation = 'add',
        string $reason = 'manual_adjustment'
    ): bool {
        $this->logger->info('Requesting stock update', [
            'product_id' => $productId,
            'quantity' => $quantity,
            'operation' => $operation,
            'reason' => $reason,
        ]);

        // Envia comando para o serviço de produtos
        $confirmed = $this->rabbitmq->publish(
            [
                'product_id' => $productId,
                'quantity' => $quantity,
                'operation' => $operation,
                'reason' => $reason,
                'requested_by' => 'store-service',
                'requested_at' => date('Y-m-d H:i:s'),
            ],
            'update.stock',
            'products.commands',
            [
                'persistent' => true,
                'priority' => 7,
                'headers' => [
                    'command_type' => 'update.stock',
                    'source' => 'store-service',
                ],
                'timeout' => 5, // Aguarda confirmação
            ]
        );

        if ($confirmed) {
            $this->logger->info('Stock update command sent', [
                'product_id' => $productId,
            ]);
        } else {
            $this->logger->error('Failed to send stock update command', [
                'product_id' => $productId,
            ]);
        }

        return $confirmed;
    }

    /**
     * Envia múltiplas atualizações de estoque em lote
     */
    public function bulkRequestStockUpdate(array $updates): array
    {
        $this->logger->info('Bulk stock update request', [
            'count' => count($updates),
        ]);

        // Prepara comandos
        $commands = array_map(function ($update) {
            return [
                'product_id' => $update['product_id'],
                'quantity' => $update['quantity'],
                'operation' => $update['operation'] ?? 'add',
                'reason' => $update['reason'] ?? 'bulk_adjustment',
                'requested_by' => 'store-service',
                'requested_at' => date('Y-m-d H:i:s'),
            ];
        }, $updates);

        // Envia em lote
        $results = $this->rabbitmq->publishBatch(
            $commands,
            'products.commands',
            fn($cmd) => 'update.stock',
            [
                'persistent' => true,
                'priority' => 6,
                'headers' => [
                    'command_type' => 'update.stock',
                    'batch_operation' => true,
                ],
            ]
        );

        $successCount = count(array_filter($results));
        $this->logger->info('Bulk stock update completed', [
            'total' => count($updates),
            'success' => $successCount,
            'failed' => count($updates) - $successCount,
        ]);

        return [
            'total' => count($updates),
            'success' => $successCount,
            'failed' => count($updates) - $successCount,
            'results' => $results,
        ];
    }

    /**
     * Consome eventos de produtos
     * Deve ser executado em um worker separado
     */
    public function consumeProductEvents(): void
    {
        $this->logger->info('Starting to consume product events');

        $this->rabbitmq->consume(
            'stores.product_events',
            function (array $data, $message, int $attempt) {
                $routingKey = $message->getRoutingKey();

                $this->logger->info('Processing product event', [
                    'routing_key' => $routingKey,
                    'attempt' => $attempt,
                    'data' => $data,
                ]);

                try {
                    // Processa evento baseado no routing key
                    match ($routingKey) {
                        'product.created' => $this->handleProductCreated($data),
                        'product.stock.updated' => $this->handleStockUpdated($data),
                        'product.price.updated' => $this->handlePriceUpdated($data),
                        'product.stock.low' => $this->handleLowStock($data),
                        default => $this->logger->warning('Unknown event type', [
                            'routing_key' => $routingKey,
                        ]),
                    };

                    return true; // ACK

                } catch (\Exception $e) {
                    $this->logger->error('Error processing product event', [
                        'error' => $e->getMessage(),
                        'routing_key' => $routingKey,
                        'attempt' => $attempt,
                    ]);

                    // Retenta até 3 vezes
                    if ($attempt >= 3) {
                        $this->logger->error('Max retries reached, sending to DLQ');
                        return false; // NACK - vai para DLQ
                    }

                    throw $e; // Retenta
                }
            },
            [
                'prefetch_count' => 10,
                'max_retries' => 3,
                'retry_delay' => 3000, // 3 segundos
                'error_strategy' => 'reject_to_dlq',
                'timeout' => 60,
            ]
        );
    }

    /**
     * Trata evento de produto criado
     */
    private function handleProductCreated(array $data): void
    {
        $this->logger->info('Handling product created', [
            'product_id' => $data['id'],
            'store_id' => $data['store_id'],
        ]);

        // Simula atualização de catálogo da loja
        // Aqui você atualizaria seu banco de dados, cache, etc.

        // Publica evento de catálogo atualizado
        $this->rabbitmq->publish(
            [
                'store_id' => $data['store_id'],
                'product_id' => $data['id'],
                'action' => 'product_added',
                'timestamp' => date('Y-m-d H:i:s'),
            ],
            'store.catalog.updated',
            'stores.events',
            [
                'persistent' => true,
                'headers' => [
                    'event_type' => 'catalog.updated',
                    'store_id' => $data['store_id'],
                ],
            ]
        );

        $this->logger->info('Product added to store catalog', [
            'product_id' => $data['id'],
            'store_id' => $data['store_id'],
        ]);
    }

    /**
     * Trata evento de estoque atualizado
     */
    private function handleStockUpdated(array $data): void
    {
        $this->logger->info('Handling stock updated', [
            'product_id' => $data['product_id'],
            'new_stock' => $data['new_stock'],
        ]);

        // Simula atualização de estoque local
        // Atualiza cache, notifica sistemas, etc.

        // Se o estoque aumentou significativamente, notifica clientes
        if ($data['operation'] === 'add' && $data['quantity'] > 50) {
            $this->rabbitmq->publish(
                [
                    'product_id' => $data['product_id'],
                    'stock_level' => $data['new_stock'],
                    'message' => 'Product back in stock!',
                    'timestamp' => date('Y-m-d H:i:s'),
                ],
                'store.notification.stock_available',
                'stores.events',
                [
                    'persistent' => true,
                    'priority' => 6,
                    'headers' => [
                        'notification_type' => 'stock_available',
                    ],
                ]
            );

            $this->logger->info('Stock available notification sent', [
                'product_id' => $data['product_id'],
            ]);
        }
    }

    /**
     * Trata evento de preço atualizado
     */
    private function handlePriceUpdated(array $data): void
    {
        $this->logger->info('Handling price updated', [
            'product_id' => $data['product_id'],
            'old_price' => $data['old_price'],
            'new_price' => $data['new_price'],
        ]);

        // Simula atualização de preço
        // Atualiza cache, recalcula promoções, etc.

        // Se o preço caiu mais de 20%, publica promoção
        if ($data['change_percentage'] < -20) {
            $this->rabbitmq->publish(
                [
                    'product_id' => $data['product_id'],
                    'old_price' => $data['old_price'],
                    'new_price' => $data['new_price'],
                    'discount_percentage' => abs($data['change_percentage']),
                    'message' => sprintf(
                        'Price dropped %.1f%%!',
                        abs($data['change_percentage'])
                    ),
                    'timestamp' => date('Y-m-d H:i:s'),
                ],
                'store.promotion.price_drop',
                'stores.events',
                [
                    'persistent' => true,
                    'priority' => 7,
                    'headers' => [
                        'promotion_type' => 'price_drop',
                    ],
                ]
            );

            $this->logger->info('Price drop promotion created', [
                'product_id' => $data['product_id'],
                'discount' => abs($data['change_percentage']),
            ]);
        }
    }

    /**
     * Trata alerta de estoque baixo
     */
    private function handleLowStock(array $data): void
    {
        $this->logger->warning('Handling low stock alert', [
            'product_id' => $data['product_id'],
            'current_stock' => $data['current_stock'],
        ]);

        // Simula ação de reabastecimento
        // Cria ordem de compra, notifica fornecedores, etc.

        // Publica alerta para o sistema de compras
        $this->rabbitmq->publish(
            [
                'product_id' => $data['product_id'],
                'current_stock' => $data['current_stock'],
                'recommended_order' => 100, // Quantidade sugerida
                'priority' => 'high',
                'timestamp' => date('Y-m-d H:i:s'),
            ],
            'store.purchasing.restock_needed',
            'stores.events',
            [
                'persistent' => true,
                'priority' => 9,
                'headers' => [
                    'alert_type' => 'restock_needed',
                    'urgency' => 'high',
                ],
            ]
        );

        $this->logger->info('Restock alert sent to purchasing', [
            'product_id' => $data['product_id'],
        ]);
    }

    /**
     * Obtém estatísticas do serviço
     */
    public function getStats(): array
    {
        $stats = $this->rabbitmq->getStats();

        return [
            'service' => 'stores',
            'connection' => [
                'is_connected' => $stats['connection']['is_connected'],
                'host' => $stats['connection']['host'],
            ],
            'publisher' => [
                'pending_confirms' => $stats['publisher']['pending_confirms'],
            ],
            'consumer' => [
                'messages_processed' => $stats['consumer']['messages_processed'],
                'messages_acked' => $stats['consumer']['messages_acked'],
                'errors' => $stats['consumer']['errors'],
            ],
        ];
    }
}
