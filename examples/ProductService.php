<?php

declare(strict_types=1);

namespace App\Services;

use Pablicio\MirabelRabbitmq\RabbitMQClient;
use Psr\Log\LoggerInterface;

/**
 * Serviço de gerenciamento de produtos
 * Publica eventos e consome comandos relacionados a produtos
 */
class ProductService
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
        // Exchange de eventos de produtos
        $this->rabbitmq->declareExchange(
            'products.events',
            'topic',
            ['durable' => true]
        );

        // Exchange de comandos para produtos
        $this->rabbitmq->declareExchange(
            'products.commands',
            'direct',
            ['durable' => true]
        );

        // Fila de comandos com DLQ
        $this->rabbitmq->createQueueWithDLQ(
            'products.update_stock',
            [
                'durable' => true,
                'message_ttl' => 300000, // 5 minutos
            ]
        );

        // Bind da fila ao exchange de comandos
        $this->rabbitmq->bindQueue(
            'products.update_stock',
            'products.commands',
            'update.stock'
        );
    }

    /**
     * Cria um novo produto e publica evento
     */
    public function createProduct(array $productData): bool
    {
        $this->logger->info('Creating product', ['data' => $productData]);

        // Simula salvamento no banco
        $product = [
            'id' => uniqid('prod_'),
            'name' => $productData['name'],
            'price' => $productData['price'],
            'stock' => $productData['stock'],
            'store_id' => $productData['store_id'],
            'created_at' => date('Y-m-d H:i:s'),
        ];

        // Publica evento de produto criado
        $confirmed = $this->rabbitmq->publish(
            $product,
            'product.created',
            'products.events',
            [
                'persistent' => true,
                'priority' => 5,
                'headers' => [
                    'event_type' => 'product.created',
                    'event_version' => '1.0',
                    'source' => 'product-service',
                ],
            ]
        );

        if ($confirmed) {
            $this->logger->info('Product created event published', [
                'product_id' => $product['id'],
            ]);
            return true;
        }

        $this->logger->error('Failed to publish product created event', [
            'product_id' => $product['id'],
        ]);
        return false;
    }

    /**
     * Atualiza estoque de um produto e notifica lojas
     */
    public function updateStock(string $productId, int $quantity, string $operation = 'add'): bool
    {
        $this->logger->info('Updating product stock', [
            'product_id' => $productId,
            'quantity' => $quantity,
            'operation' => $operation,
        ]);

        // Simula atualização no banco
        $newStock = $operation === 'add' ? 100 + $quantity : 100 - $quantity;

        // Publica evento de estoque atualizado
        $event = [
            'product_id' => $productId,
            'quantity' => $quantity,
            'operation' => $operation,
            'new_stock' => $newStock,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $confirmed = $this->rabbitmq->publish(
            $event,
            'product.stock.updated',
            'products.events',
            [
                'persistent' => true,
                'headers' => [
                    'event_type' => 'stock.updated',
                    'product_id' => $productId,
                ],
            ]
        );

        // Se estoque baixo, publica alerta
        if ($newStock < 10) {
            $this->publishLowStockAlert($productId, $newStock);
        }

        return $confirmed;
    }

    /**
     * Publica alerta de estoque baixo
     */
    private function publishLowStockAlert(string $productId, int $stock): void
    {
        $this->rabbitmq->publish(
            [
                'product_id' => $productId,
                'current_stock' => $stock,
                'alert_level' => 'warning',
                'timestamp' => date('Y-m-d H:i:s'),
            ],
            'product.stock.low',
            'products.events',
            [
                'persistent' => true,
                'priority' => 8,
                'headers' => [
                    'alert_type' => 'low_stock',
                    'urgency' => 'high',
                ],
            ]
        );

        $this->logger->warning('Low stock alert published', [
            'product_id' => $productId,
            'stock' => $stock,
        ]);
    }

    /**
     * Atualiza preço de um produto
     */
    public function updatePrice(string $productId, float $newPrice, float $oldPrice): bool
    {
        $this->logger->info('Updating product price', [
            'product_id' => $productId,
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
        ]);

        // Publica evento de preço atualizado
        return $this->rabbitmq->publish(
            [
                'product_id' => $productId,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'change_percentage' => (($newPrice - $oldPrice) / $oldPrice) * 100,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            'product.price.updated',
            'products.events',
            [
                'persistent' => true,
                'headers' => [
                    'event_type' => 'price.updated',
                    'product_id' => $productId,
                ],
            ]
        );
    }

    /**
     * Publica múltiplos produtos em lote
     */
    public function bulkCreateProducts(array $products): array
    {
        $this->logger->info('Bulk creating products', [
            'count' => count($products),
        ]);

        // Prepara produtos com IDs
        $productsWithIds = array_map(function ($product) {
            return [
                'id' => uniqid('prod_'),
                'name' => $product['name'],
                'price' => $product['price'],
                'stock' => $product['stock'],
                'store_id' => $product['store_id'],
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }, $products);

        // Publica em lote
        $results = $this->rabbitmq->publishBatch(
            $productsWithIds,
            'products.events',
            fn($product) => 'product.created',
            [
                'persistent' => true,
                'headers' => [
                    'event_type' => 'product.created',
                    'batch_operation' => true,
                ],
            ]
        );

        $successCount = count(array_filter($results));
        $this->logger->info('Bulk create completed', [
            'total' => count($products),
            'success' => $successCount,
            'failed' => count($products) - $successCount,
        ]);

        return [
            'total' => count($products),
            'success' => $successCount,
            'failed' => count($products) - $successCount,
            'results' => $results,
        ];
    }

    /**
     * Consome comandos de atualização de estoque
     * Deve ser executado em um worker separado
     */
    public function consumeStockUpdateCommands(): void
    {
        $this->logger->info('Starting to consume stock update commands');

        $this->rabbitmq->consume(
            'products.update_stock',
            function (array $data, $message, int $attempt) {
                $this->logger->info('Processing stock update command', [
                    'attempt' => $attempt,
                    'data' => $data,
                ]);

                try {
                    // Valida dados
                    if (!isset($data['product_id'], $data['quantity'], $data['operation'])) {
                        $this->logger->error('Invalid stock update command', ['data' => $data]);
                        return false; // NACK - vai para DLQ
                    }

                    // Processa atualização
                    $success = $this->updateStock(
                        $data['product_id'],
                        $data['quantity'],
                        $data['operation']
                    );

                    if ($success) {
                        $this->logger->info('Stock update processed successfully', [
                            'product_id' => $data['product_id'],
                        ]);
                        return true; // ACK
                    }

                    // Se falhou, tenta novamente
                    throw new \RuntimeException('Failed to update stock');

                } catch (\Exception $e) {
                    $this->logger->error('Error processing stock update', [
                        'error' => $e->getMessage(),
                        'attempt' => $attempt,
                    ]);

                    // Se já tentou muito, rejeita
                    if ($attempt >= 3) {
                        $this->logger->error('Max retries reached, sending to DLQ', [
                            'product_id' => $data['product_id'] ?? 'unknown',
                        ]);
                        return false; // NACK - vai para DLQ
                    }

                    throw $e; // Retenta
                }
            },
            [
                'prefetch_count' => 5,
                'max_retries' => 3,
                'retry_delay' => 5000, // 5 segundos
                'error_strategy' => 'reject_to_dlq',
                'timeout' => 30,
            ]
        );
    }

    /**
     * Obtém estatísticas do serviço
     */
    public function getStats(): array
    {
        $stats = $this->rabbitmq->getStats();

        return [
            'service' => 'products',
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
