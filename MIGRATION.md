# Guia de Migração - Mirabel RabbitMQ v2.0

## 📋 Resumo das Mudanças

A versão 2.0 do Mirabel RabbitMQ traz melhorias significativas em confiabilidade, performance e usabilidade.

### Novos Recursos

1. **Reconexão Automática** - Gerencia falhas de conexão automaticamente
2. **Pool de Canais** - Reutiliza canais para melhor performance
3. **Publisher Confirms** - Garante que mensagens foram recebidas pelo broker
4. **Dead Letter Queues** - Sistema integrado de DLQ com retry multi-nível
5. **Batch Publishing** - Publica múltiplas mensagens eficientemente
6. **QoS Configurável** - Controle fino sobre consumo de mensagens
7. **Graceful Shutdown** - Desligamento seguro de consumers
8. **Builder Pattern** - Configuração fluente e intuitiva

## 🔄 Migração da API Antiga

### Publisher

**Antes (v1.x):**
```php
$publisher = new Publisher($connectionManager, $serializer, $config);
$publisher->toExchange('orders')->publish('order.created', $data);
```

**Agora (v2.0):**
```php
// Opção 1: API antiga ainda funciona
$publisher = new Publisher($connectionManager, $serializer, $config);
$publisher->toExchange('orders')->publish('order.created', $data);

// Opção 2: Nova API simplificada
$client = RabbitMQClientBuilder::create()->localhost()->build();
$client->publish($data, 'order.created', 'orders');

// Opção 3: Com confirmação
$confirmed = $client->publish(
    $data,
    'order.created',
    'orders',
    ['timeout' => 5]
);
```

### Consumer

**Antes (v1.x):**
```php
$consumer = new Consumer($connectionManager, $serializer, $config);
$consumer->consume('orders', function($data, $message) {
    processOrder($data);
    $message->ack();
});
```

**Agora (v2.0):**
```php
// Opção 1: API antiga ainda funciona
$consumer = new Consumer($connectionManager, $serializer, $config);
$consumer->consume('orders', function($data, $message) {
    processOrder($data);
    $message->ack();
});

// Opção 2: Nova API com auto-ACK
$client = RabbitMQClientBuilder::create()->localhost()->build();
$client->consume('orders', function($data) {
    processOrder($data);
    return true; // ACK automático
}, [
    'prefetch_count' => 10,
    'max_retries' => 3,
    'retry_delay' => 5000,
]);
```

## 🆕 Novos Padrões de Uso

### 1. Configuração com Builder

```php
use Pablicio\MirabelRabbitmq\RabbitMQClientBuilder;

// Desenvolvimento local
$client = RabbitMQClientBuilder::create()
    ->localhost()
    ->build();

// Produção com todas as opções
$client = RabbitMQClientBuilder::create()
    ->host('rabbitmq.prod.com')
    ->port(5672)
    ->user('app_user')
    ->password(env('RABBITMQ_PASSWORD'))
    ->vhost('/production')
    ->heartbeat(60)
    ->maxReconnectAttempts(10)
    ->reconnectDelay(3)
    ->maxChannels(20)
    ->logger($logger)
    ->build();

// Do Laravel config
$client = RabbitMQClientBuilder::create()
    ->fromLaravel('default')
    ->build();
```

### 2. Publisher com Confirmação

```php
// Publica e aguarda confirmação
$confirmed = $client->publish(
    ['order_id' => 123, 'total' => 99.99],
    'orders.processing',
    'orders.exchange',
    [
        'persistent' => true,
        'priority' => 5,
        'timeout' => 10, // aguarda até 10s
    ]
);

if (!$confirmed) {
    // Trata falha
    Log::error('Message not confirmed');
    // Retenta, alerta, etc
}
```

### 3. Batch Publishing

```php
$orders = [
    ['id' => 1, 'total' => 10.00],
    ['id' => 2, 'total' => 20.00],
    ['id' => 3, 'total' => 30.00],
];

$results = $client->publishBatch(
    $orders,
    'orders.exchange',
    fn($order) => "orders.{$order['id']}",
    ['persistent' => true]
);

// $results = [true, true, false]
$success = count(array_filter($results));
$failed = count($results) - $success;

Log::info("Published {$success} messages, {$failed} failed");
```

### 4. Consumer com Retry Automático

```php
$client->consume(
    'orders.processing',
    function ($data, $message, $attempt) {
        Log::info("Processing order (attempt {$attempt})");
        
        try {
            processOrder($data);
            return true; // ACK
        } catch (TemporaryException $e) {
            // Será retentado automaticamente
            throw $e;
        } catch (PermanentException $e) {
            // Rejeita para DLQ
            return false;
        }
    },
    [
        'prefetch_count' => 5,
        'max_retries' => 3,
        'retry_delay' => 5000, // 5 segundos
        'error_strategy' => 'reject_to_dlq',
    ]
);
```

### 5. Dead Letter Queue Setup

```php
// Cria fila com DLQ automática
$setup = $client->createQueueWithDLQ(
    'orders.processing',
    [
        'durable' => true,
        'message_ttl' => 300000, // 5 minutos
        'dlq_max_length' => 1000,
    ]
);

// Retorna:
// [
//     'queue' => 'orders.processing',
//     'dlx' => 'orders.processing.dlx',
//     'dlq' => 'orders.processing.dlq',
//     'routing_key' => 'orders.processing'
// ]

// Faz bind ao exchange
$client->bindQueue(
    'orders.processing',
    'orders.exchange',
    'orders.*'
);
```

### 6. Retry Multi-Nível

```php
$dlqManager = $client->getDLQManager();

$structure = $dlqManager->createMultiLevelRetryStructure(
    'critical.tasks',
    [1000, 5000, 30000], // Delays: 1s, 5s, 30s
    ['exchange' => 'tasks.exchange']
);

// Cria automaticamente:
// - critical.tasks (fila principal)
// - critical.tasks.retry.1000ms (retry nível 1)
// - critical.tasks.retry.5000ms (retry nível 2)
// - critical.tasks.retry.30000ms (retry nível 3)
// - critical.tasks.dlq.final (DLQ final)
```

### 7. Gerenciamento de DLQ

```php
$dlqManager = $client->getDLQManager();

// Informações da DLQ
$info = $dlqManager->getDLQInfo('orders.processing.dlq');
echo "Messages in DLQ: {$info['message_count']}\n";

// Reprocessa mensagens
$reprocessed = $dlqManager->reprocessDLQ(
    'orders.processing.dlq',
    'orders.exchange',
    'orders.processing',
    10 // limite de mensagens
);

// Purga DLQ
$purged = $dlqManager->purgeDLQ('orders.processing.dlq');
```

### 8. Monitoramento e Estatísticas

```php
// Obtém estatísticas em tempo real
$stats = $client->getStats();

// Monitora conexão
if (!$stats['connection']['is_connected']) {
    Alert::send('RabbitMQ disconnected!');
}

// Monitora erros
if ($stats['consumer']['errors'] > 100) {
    Alert::send('High error rate in consumer!');
}

// Monitora publisher
if ($stats['publisher']['pending_confirms'] > 1000) {
    Alert::send('Too many pending confirms!');
}

// Envia para sistema de métricas
Metrics::gauge('rabbitmq.channels.active', $stats['channel_pool']['total_channels']);
Metrics::counter('rabbitmq.messages.processed', $stats['consumer']['messages_processed']);
```

### 9. Callbacks de Reconexão

```php
$connectionManager = $client->getConnectionManager();

// Quando conectar
$connectionManager->onConnection(function($connection) {
    Log::info('Connected to RabbitMQ');
    
    // Recria consumers, exchanges, etc
    restoreTopology();
});

// Quando desconectar
$connectionManager->onDisconnection(function($connection) {
    Log::warning('Disconnected from RabbitMQ');
    
    // Notifica, limpa recursos, etc
    notifyOps('RabbitMQ connection lost');
});
```

### 10. Graceful Shutdown

```php
// O consumer já tem signal handlers
$consumer = $client->getConsumer();

$consumer->consume('tasks', function($data) {
    // Processamento longo
    processTask($data);
    return true;
});

// Ao receber SIGTERM ou SIGINT:
// 1. Para de aceitar novas mensagens
// 2. Aguarda mensagem atual terminar
// 3. Faz ACK/NACK apropriado
// 4. Fecha conexões graciosamente

// Ou manualmente:
$consumer->stop();
```

## 📊 Performance Tips

### 1. Use Batch Publishing

```php
// ❌ Lento - múltiplas conexões
foreach ($messages as $message) {
    $client->publish($message, 'queue');
}

// ✅ Rápido - uma conexão, confirmações em lote
$client->publishBatch($messages, 'exchange', fn($m) => 'queue');
```

### 2. Configure QoS Adequadamente

```php
// ❌ Sobrecarrega um consumer
$client->setQoS(0); // Sem limite

// ✅ Balanceia entre consumers
$client->setQoS(10); // 10 mensagens por consumer
```

### 3. Use Pool de Canais

```php
// ✅ Reutiliza canais automaticamente
$channelPool = $client->getChannelPool();
$stats = $channelPool->getStats();

// Monitora uso
if ($stats['total_channels'] >= $stats['max_channels']) {
    Log::warning('Channel pool exhausted');
}
```

### 4. Publisher Confirms Seletivo

```php
// Para mensagens críticas
$confirmed = $client->publish(
    $criticalData,
    'critical.queue',
    'exchange',
    ['timeout' => 10]
);

// Para mensagens não críticas (mais rápido)
$client->publish(
    $nonCriticalData,
    'logs.queue',
    'exchange'
    // Sem timeout = não aguarda confirmação
);
```

## 🔒 Segurança

```php
// SSL/TLS
$client = RabbitMQClientBuilder::create()
    ->host('secure.rabbitmq.com')
    ->port(5671)
    ->fromArray([
        'ssl_protocol' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        'context' => stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'cafile' => '/path/to/ca.pem',
            ],
        ]),
    ])
    ->build();
```

## 🧪 Testes

```php
use Tests\TestCase;
use Pablicio\MirabelRabbitmq\RabbitMQClientBuilder;

class OrderProcessingTest extends TestCase
{
    public function test_can_process_order()
    {
        $client = RabbitMQClientBuilder::create()
            ->localhost()
            ->build();

        // Publica ordem de teste
        $client->publish(
            ['order_id' => 123],
            'orders.test'
        );

        // Consome e verifica
        $processed = false;
        $client->consume('orders.test', function($data) use (&$processed) {
            $processed = true;
            $this->assertEquals(123, $data['order_id']);
            return true;
        }, ['timeout' => 5]);

        $this->assertTrue($processed);
    }
}
```

## ❓ FAQ

**P: A API antiga ainda funciona?**
R: Sim! Mantivemos compatibilidade com Publisher e Consumer antigos.

**P: Como migro gradualmente?**
R: Use a API antiga onde já está funcionando e a nova para código novo.

**P: Publisher Confirms afeta performance?**
R: Sim, mas garante entrega. Use seletivamente para mensagens críticas.

**P: Pool de canais é thread-safe?**
R: Sim, mas PHP não tem threads reais. É process-safe para múltiplos workers.

**P: Como monitoro a saúde do sistema?**
R: Use `$client->getStats()` e integre com seu sistema de métricas.

## 📞 Suporte

- Issues: [GitHub Issues](https://github.com/pablicio/mirabel-rabbitmq/issues)
- Email: pabliciotjg@gmail.com
- Documentação: [README.md](README.md)
