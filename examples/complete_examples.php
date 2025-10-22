<?php

require __DIR__ . '/../vendor/autoload.php';

use Pablicio\MirabelRabbitmq\RabbitMQClientBuilder;

// ============================================
// Exemplo 1: Configuração Simples com Builder
// ============================================

echo "=== Exemplo 1: Configuração Simples ===\n";

$client = RabbitMQClientBuilder::create()
    ->localhost()
    ->build();

// Publica uma mensagem simples
$client->publish(
    ['message' => 'Hello RabbitMQ!'],
    'test.queue'
);

echo "Mensagem publicada com sucesso!\n\n";

// ============================================
// Exemplo 2: Configuração Avançada
// ============================================

echo "=== Exemplo 2: Configuração Avançada ===\n";

$client = RabbitMQClientBuilder::create()
    ->host('localhost')
    ->port(5672)
    ->user('guest')
    ->password('guest')
    ->vhost('/')
    ->heartbeat(60)
    ->maxReconnectAttempts(10)
    ->reconnectDelay(3)
    ->maxChannels(20)
    ->build();

// Publica com opções avançadas
$client->publish(
    ['order_id' => 123, 'customer' => 'John Doe'],
    'orders.processing',
    'orders.exchange',
    [
        'persistent' => true,
        'priority' => 5,
        'expiration' => 60000, // 60 segundos
        'headers' => [
            'x-source' => 'api',
            'x-version' => '1.0',
        ],
    ]
);

echo "Mensagem com opções avançadas publicada!\n\n";

// ============================================
// Exemplo 3: Publicação em Lote
// ============================================

echo "=== Exemplo 3: Publicação em Lote ===\n";

$messages = [
    ['id' => 1, 'name' => 'Product 1'],
    ['id' => 2, 'name' => 'Product 2'],
    ['id' => 3, 'name' => 'Product 3'],
];

$results = $client->publishBatch(
    $messages,
    'products.exchange',
    fn($message) => 'products.created',
    ['persistent' => true]
);

echo "Publicadas " . count(array_filter($results)) . " mensagens com sucesso!\n\n";

// ============================================
// Exemplo 4: Declarar Exchange e Queue
// ============================================

echo "=== Exemplo 4: Declarar Exchange e Queue ===\n";

// Declara exchange
$client->declareExchange(
    'notifications.exchange',
    'topic',
    ['durable' => true]
);

// Declara fila
$queueInfo = $client->declareQueue(
    'notifications.email',
    ['durable' => true]
);

echo "Fila criada: {$queueInfo['queue']}\n";
echo "Mensagens na fila: {$queueInfo['message_count']}\n";

// Faz bind
$client->bindQueue(
    'notifications.email',
    'notifications.exchange',
    'email.*'
);

echo "Queue bound ao exchange!\n\n";

// ============================================
// Exemplo 5: Fila com Dead Letter Queue
// ============================================

echo "=== Exemplo 5: Fila com DLQ ===\n";

$dlqSetup = $client->createQueueWithDLQ(
    'orders.processing',
    [
        'durable' => true,
        'message_ttl' => 300000, // 5 minutos
        'dlq_max_length' => 1000,
    ]
);

echo "Fila principal: {$dlqSetup['queue']}\n";
echo "Dead Letter Exchange: {$dlqSetup['dlx']}\n";
echo "Dead Letter Queue: {$dlqSetup['dlq']}\n\n";

// ============================================
// Exemplo 6: Consumir Mensagens
// ============================================

echo "=== Exemplo 6: Consumir Mensagens ===\n";

// Declara a fila de teste
$client->declareQueue('test.queue', ['durable' => true]);

// Publica algumas mensagens de teste
for ($i = 1; $i <= 5; $i++) {
    $client->publish(
        ['id' => $i, 'message' => "Test message {$i}"],
        'test.queue'
    );
}

echo "Consumindo mensagens...\n";

// Consome mensagens
$messagesProcessed = 0;
$client->consume(
    'test.queue',
    function ($data, $message) use (&$messagesProcessed) {
        echo "Processando: {$data['message']}\n";
        $messagesProcessed++;
        
        // Para após processar 5 mensagens
        if ($messagesProcessed >= 5) {
            return ['action' => 'ack'];
        }
        
        return true; // ACK
    },
    [
        'prefetch_count' => 1,
        'timeout' => 5,
    ]
);

echo "Total processadas: {$messagesProcessed}\n\n";

// ============================================
// Exemplo 7: Consumo com Retry
// ============================================

echo "=== Exemplo 7: Consumo com Retry ===\n";

$client->consume(
    'orders.processing',
    function ($data, $message, $attempt) {
        echo "Tentativa {$attempt} - Processando pedido #{$data['order_id']}\n";
        
        // Simula erro nas primeiras 2 tentativas
        if ($attempt < 2) {
            throw new \Exception('Erro temporário no processamento');
        }
        
        echo "Pedido processado com sucesso!\n";
        return true;
    },
    [
        'prefetch_count' => 1,
        'max_retries' => 3,
        'retry_delay' => 1000, // 1 segundo
        'error_strategy' => 'reject_to_dlq',
    ]
);

// ============================================
// Exemplo 8: Configurar QoS
// ============================================

echo "\n=== Exemplo 8: Configurar QoS ===\n";

// Configura para processar 10 mensagens por vez
$client->setQoS(10);

echo "QoS configurado: 10 mensagens por vez\n\n";

// ============================================
// Exemplo 9: Estatísticas
// ============================================

echo "=== Exemplo 9: Estatísticas ===\n";

$stats = $client->getStats();

echo "Conexão:\n";
echo "  Conectado: " . ($stats['connection']['is_connected'] ? 'Sim' : 'Não') . "\n";
echo "  Host: {$stats['connection']['host']}:{$stats['connection']['port']}\n";
echo "  Tentativas de reconexão: {$stats['connection']['reconnect_attempts']}\n\n";

echo "Pool de Canais:\n";
echo "  Total de canais: {$stats['channel_pool']['total_channels']}\n";
echo "  Máximo de canais: {$stats['channel_pool']['max_channels']}\n\n";

echo "Publisher:\n";
echo "  Confirmações pendentes: {$stats['publisher']['pending_confirms']}\n";
echo "  Modo de confirmação: " . ($stats['publisher']['confirm_mode'] ? 'Ativo' : 'Inativo') . "\n\n";

echo "Consumer:\n";
echo "  Mensagens processadas: {$stats['consumer']['messages_processed']}\n";
echo "  Mensagens ACK: {$stats['consumer']['messages_acked']}\n";
echo "  Mensagens NACK: {$stats['consumer']['messages_nacked']}\n";
echo "  Erros: {$stats['consumer']['errors']}\n\n";

// ============================================
// Exemplo 10: Usando Builder com Arquivo
// ============================================

echo "=== Exemplo 10: Carregar Configuração ===\n";

// De array
$client = RabbitMQClientBuilder::create()
    ->fromArray([
        'host' => 'localhost',
        'port' => 5672,
        'user' => 'guest',
        'password' => 'guest',
    ])
    ->build();

echo "Cliente criado a partir de array\n";

// De arquivo JSON (exemplo)
// $client = RabbitMQClientBuilder::create()
//     ->fromFile(__DIR__ . '/config.json')
//     ->build();

// Do Laravel (exemplo)
// $client = RabbitMQClientBuilder::create()
//     ->fromLaravel('default')
//     ->build();

// Fecha conexão
$client->close();

echo "\nTodos os exemplos executados com sucesso!\n";
