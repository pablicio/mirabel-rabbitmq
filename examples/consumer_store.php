<?php

/**
 * Worker do StoreService
 * Consome eventos de produtos
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Services\StoreService;
use Pablicio\MirabelRabbitmq\RabbitMQClientBuilder;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Configuração de erro reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Logger
$logger = new Logger('store-consumer');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));
$logger->pushHandler(new StreamHandler(__DIR__ . '/store-consumer.log', Logger::INFO));

// Cliente RabbitMQ
$rabbitmqClient = RabbitMQClientBuilder::create()
    ->localhost()
    ->heartbeat(60)
    ->maxReconnectAttempts(10)
    ->reconnectDelay(3)
    ->logger($logger)
    ->build();

// Serviço
$storeService = new StoreService($rabbitmqClient, $logger);

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║      🏪 StoreService Consumer v2.0                      ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "📋 Configuração:\n";
echo "   Host: localhost:5672\n";
echo "   Queue: stores.product_events\n";
echo "   Prefetch: 10 mensagens\n";
echo "   Max Retries: 3 tentativas\n";
echo "   Retry Delay: 3 segundos\n";
echo "\n";
echo "⏳ Aguardando eventos de produtos...\n";
echo "📬 Eventos escutados:\n";
echo "   • product.created\n";
echo "   • product.stock.updated\n";
echo "   • product.price.updated\n";
echo "   • product.stock.low\n";
echo "\n";
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

// Contador de eventos processados por tipo
$eventCounters = [
    'product.created' => 0,
    'product.stock.updated' => 0,
    'product.price.updated' => 0,
    'product.stock.low' => 0,
];

// Handler de estatísticas periódicas
pcntl_signal(SIGUSR1, function() use ($storeService, &$eventCounters) {
    echo "\n";
    echo "═══════════════════════════════════════════════════════\n";
    echo "📊 Estatísticas do StoreService\n";
    echo "═══════════════════════════════════════════════════════\n";
    
    $stats = $storeService->getStats();
    print_r($stats);
    
    echo "\n📈 Eventos por Tipo:\n";
    foreach ($eventCounters as $type => $count) {
        echo "   {$type}: {$count}\n";
    }
    
    echo "═══════════════════════════════════════════════════════\n\n";
});

try {
    // Consome mensagens (loop infinito)
    $storeService->consumeProductEvents();
    
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
echo "👋 StoreService Consumer finalizado.\n";
echo "═══════════════════════════════════════════════════════\n";
