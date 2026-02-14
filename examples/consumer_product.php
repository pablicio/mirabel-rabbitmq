<?php

/**
 * Worker do ProductService
 * Consome comandos de atualização de estoque
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Services\ProductService;
use Pablicio\MirabelRabbitmq\RabbitMQClientBuilder;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Configuração de erro reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Logger
$logger = new Logger('product-consumer');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));
$logger->pushHandler(new StreamHandler(__DIR__ . '/product-consumer.log', Logger::INFO));

// Cliente RabbitMQ
$rabbitmqClient = RabbitMQClientBuilder::create()
    ->localhost()
    ->heartbeat(60)
    ->maxReconnectAttempts(10)
    ->reconnectDelay(3)
    ->logger($logger)
    ->build();

// Serviço
$productService = new ProductService($rabbitmqClient, $logger);

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║      🚀 ProductService Consumer v2.0                    ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "📋 Configuração:\n";
echo "   Host: localhost:5672\n";
echo "   Queue: products.update_stock\n";
echo "   Prefetch: 5 mensagens\n";
echo "   Max Retries: 3 tentativas\n";
echo "   Retry Delay: 5 segundos\n";
echo "\n";
echo "⏳ Aguardando comandos de atualização de estoque...\n";
echo "💡 Pressione Ctrl+C para parar graciosamente\n";
echo "══════════════════════════════════════════════════════════\n\n";

// Callbacks de conexão
$connectionManager = $rabbitmqClient->getConnectionManager();

$connectionManager->onConnection(function() use ($logger) {
    $logger->info('✅ Conectado ao RabbitMQ');
    echo "[" . date('Y-m-d H:i:s') . "] ✅ Conectado ao RabbitMQ\n";
});

$connectionManager->onDisconnection(function() use ($logger) {
    $logger->warning('⚠️  Desconectado do RabbitMQ');
    echo "[" . date('Y-m-d H:i:s') . "] ⚠️  Desconectado do RabbitMQ\n";
});

// Handler de estatísticas periódicas
$lastStatsTime = time();
$statsInterval = 30; // 30 segundos

// Registra handler para exibir estatísticas
pcntl_signal(SIGUSR1, function() use ($productService) {
    echo "\n";
    echo "═══════════════════════════════════════════════════════\n";
    echo "📊 Estatísticas do ProductService\n";
    echo "═══════════════════════════════════════════════════════\n";
    $stats = $productService->getStats();
    print_r($stats);
    echo "═══════════════════════════════════════════════════════\n\n";
});

try {
    // Consome mensagens (loop infinito)
    $productService->consumeStockUpdateCommands();
    
} catch (\Exception $e) {
    $logger->error('❌ Erro fatal no consumer', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    
    echo "\n";
    echo "❌ Erro fatal: {$e->getMessage()}\n";
    echo "Verifique os logs para mais detalhes.\n";
    exit(1);
}

echo "\n";
echo "👋 ProductService Consumer finalizado.\n";
echo "═══════════════════════════════════════════════════════\n";
