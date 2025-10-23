# 📘 Guia Passo a Passo - Mirabel RabbitMQ com Laravel

Este documento detalha **todos os passos** necessários para configurar e usar a biblioteca Mirabel RabbitMQ em um projeto Laravel externo.

## 📑 Índice

1. [Pré-requisitos](#1-pré-requisitos)
2. [Instalação](#2-instalação)
3. [Configuração Inicial](#3-configuração-inicial)
4. [Criar seu Primeiro Evento (Publisher)](#4-criar-seu-primeiro-evento-publisher)
5. [Publicar o Evento](#5-publicar-o-evento)
6. [Criar seu Primeiro Worker (Consumer)](#6-criar-seu-primeiro-worker-consumer)
7. [Executar o Worker](#7-executar-o-worker)
8. [Configurar Supervisor para Produção](#8-configurar-supervisor-para-produção)
9. [Monitoramento e Debug](#9-monitoramento-e-debug)
10. [Exemplos Completos](#10-exemplos-completos)

---

## 1. Pré-requisitos

### 1.1 RabbitMQ Instalado

**Usando Docker (Recomendado):**

```bash
docker run -d --name rabbitmq \
  -p 5672:5672 \
  -p 15672:15672 \
  -e RABBITMQ_DEFAULT_USER=guest \
  -e RABBITMQ_DEFAULT_PASS=guest \
  rabbitmq:3-management
```

**Verificar se está rodando:**
```bash
# Acesse o Management UI
http://localhost:15672
# Login: guest / guest
```

### 1.2 Projeto Laravel

```bash
# Laravel 10 ou 11
php artisan --version
```

---

## 2. Instalação

### 2.1 Instalar via Composer

```bash
cd /caminho/do/seu/projeto-laravel
composer require pablicio/mirabel-rabbitmq
```

### 2.2 Verificar Instalação

```bash
composer show pablicio/mirabel-rabbitmq
```

Você deve ver algo como:
```
name     : pablicio/mirabel-rabbitmq
descrip. : A modern, Laravel-integrated RabbitMQ library
versions : * 2.0.0
```

---

## 3. Configuração Inicial

### 3.1 Publicar o Arquivo de Configuração

```bash
php artisan vendor:publish --provider="Pablicio\MirabelRabbitmq\MirabelRabbitmqServiceProvider"
```

**Resultado:**
```
Copied File [/vendor/pablicio/mirabel-rabbitmq/config/rabbitmq.php] 
To [/config/rabbitmq.php]
```

### 3.2 Configurar Variáveis de Ambiente

Abra o arquivo `.env` do seu projeto e adicione:

```env
# RabbitMQ Connection
RABBITMQ_HOST=localhost
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/

# Exchange Configuration
RABBITMQ_EXCHANGE=meu-app.events
RABBITMQ_EXCHANGE_TYPE=topic

# Performance Settings
RABBITMQ_HEARTBEAT=60
RABBITMQ_MAX_CHANNELS=10
RABBITMQ_PREFETCH_COUNT=1
RABBITMQ_MAX_RECONNECT_ATTEMPTS=5
RABBITMQ_RECONNECT_DELAY=2

# Default Connection
RABBITMQ_CONNECTION=default
```

### 3.3 Verificar Configuração

Abra `config/rabbitmq.php` e confirme que está assim:

```php
<?php

return [
    'default' => env('RABBITMQ_CONNECTION', 'default'),

    'connections' => [
        'default' => [
            'host' => env('RABBITMQ_HOST', 'localhost'),
            'port' => env('RABBITMQ_PORT', 5672),
            'user' => env('RABBITMQ_USER', 'guest'),
            'password' => env('RABBITMQ_PASSWORD', 'guest'),
            'vhost' => env('RABBITMQ_VHOST', '/'),
            
            // Exchange
            'exchange' => env('RABBITMQ_EXCHANGE', ''),
            'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'topic'),
            
            // Connection settings
            'heartbeat' => env('RABBITMQ_HEARTBEAT', 60),
            'connection_timeout' => 3.0,
            'read_write_timeout' => 3.0,
            'keepalive' => true,
            
            // Reconnection
            'max_reconnect_attempts' => env('RABBITMQ_MAX_RECONNECT_ATTEMPTS', 5),
            'reconnect_delay' => env('RABBITMQ_RECONNECT_DELAY', 2),
            
            // Pool
            'max_channels' => env('RABBITMQ_MAX_CHANNELS', 10),
            
            // Default exchange
            'default_exchange' => env('RABBITMQ_EXCHANGE', ''),
        ],
    ],

    // QoS
    'qos' => [
        'prefetch_count' => env('RABBITMQ_PREFETCH_COUNT', 1),
        'prefetch_size' => 0,
        'global' => false,
    ],

    // DLQ
    'dlq' => [
        'enabled' => true,
        'suffix' => '.dlq',
        'dlx_suffix' => '.dlx',
    ],

    // Retry
    'retry' => [
        'enabled' => true,
        'max_attempts' => 3,
        'delay_ms' => 5000,
    ],

    // Publisher
    'publisher' => [
        'confirm_mode' => true,
        'confirm_timeout' => 5,
        'persistent' => true,
    ],

    // Consumer
    'consumer' => [
        'prefetch_count' => 1,
        'timeout' => 0,
        'stop_on_error' => false,
    ],
];
```

### 3.4 Testar Conexão

Crie um arquivo de teste `routes/web.php`:

```php
use Pablicio\MirabelRabbitmq\Facades\RabbitMQ;

Route::get('/test-rabbitmq', function () {
    try {
        $stats = RabbitMQ::getStats();
        return response()->json([
            'status' => 'success',
            'connected' => $stats['connection']['is_connected'],
            'stats' => $stats
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500);
    }
});
```

Acesse: `http://seu-app.test/test-rabbitmq`

---

## 4. Criar seu Primeiro Evento (Publisher)

### 4.1 Criar Diretório de Events

```bash
mkdir -p app/Events
```

### 4.2 Criar a Classe do Evento

Crie o arquivo `app/Events/OrderCreatedEvent.php`:

```php
<?php

namespace App\Events;

use Pablicio\MirabelRabbitmq\Traits\RabbitMQEventsConnection;

class OrderCreatedEvent
{
    use RabbitMQEventsConnection;

    const ROUTING_KEY = 'orders.created';

    public function __construct(array $orderData)
    {
        $this->routingKey = self::ROUTING_KEY;
        $this->payload = $orderData;
    }
}
```

**Pronto!** Isso é tudo que você precisa para criar um evento.

---

## 5. Publicar o Evento

### 5.1 Em um Controller

Crie `app/Http/Controllers/OrderController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Events\OrderCreatedEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        // Validação
        $validated = $request->validate([
            'customer_name' => 'required|string',
            'items' => 'required|array',
            'total' => 'required|numeric',
        ]);

        // Cria o pedido (exemplo simplificado)
        $order = [
            'id' => uniqid('order_'),
            'customer_name' => $validated['customer_name'],
            'items' => $validated['items'],
            'total' => $validated['total'],
            'status' => 'pending',
            'created_at' => now()->toIso8601String(),
        ];

        // Salvar no banco de dados (opcional)
        // Order::create($order);

        // 🎉 PUBLICAR O EVENTO
        try {
            $published = (new OrderCreatedEvent($order))->publish();
            
            if ($published) {
                Log::info('Order event published', ['order_id' => $order['id']]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to publish order event', [
                'order_id' => $order['id'],
                'error' => $e->getMessage()
            ]);
        }

        return response()->json([
            'message' => 'Order created successfully',
            'order' => $order
        ], 201);
    }
}
```

### 5.2 Criar a Rota

Em `routes/api.php`:

```php
use App\Http\Controllers\OrderController;

Route::post('/orders', [OrderController::class, 'store']);
```

### 5.3 Testar a Publicação

```bash
curl -X POST http://seu-app.test/api/orders \
  -H "Content-Type: application/json" \
  -d '{
    "customer_name": "João Silva",
    "items": [
      {"product": "Notebook", "quantity": 1, "price": 3500}
    ],
    "total": 3500
  }'
```

**Resposta esperada:**
```json
{
  "message": "Order created successfully",
  "order": {
    "id": "order_6543210abc",
    "customer_name": "João Silva",
    "items": [...],
    "total": 3500,
    "status": "pending",
    "created_at": "2025-10-22T10:30:00Z"
  }
}
```

### 5.4 Verificar no RabbitMQ

Acesse: `http://localhost:15672/#/queues`

Você NÃO verá mensagens ainda, pois não há consumers. A mensagem está no exchange aguardando consumidores.

---

## 6. Criar seu Primeiro Worker (Consumer)

### 6.1 Criar Diretório de Workers

```bash
mkdir -p app/Workers
```

### 6.2 Criar a Classe do Worker

Crie o arquivo `app/Workers/OrderProcessorWorker.php`:

```php
<?php

namespace App\Workers;

use Pablicio\MirabelRabbitmq\Traits\RabbitMQWorkersConnection;
use Illuminate\Support\Facades\Log;

class OrderProcessorWorker
{
    use RabbitMQWorkersConnection;

    // Nome da fila
    const QUEUE = 'orders.processing';
    
    // Routing keys que este worker escuta
    const ROUTING_KEYS = [
        'orders.created',
        'orders.updated'
    ];
    
    // Opções do exchange
    const OPTIONS = [
        'exchange_type' => 'topic'
    ];
    
    // Configuração de retry
    const RETRY_OPTIONS = [
        'x-message-ttl' => 5000,  // 5 segundos entre retries
        'max-attempts' => 3        // Máximo 3 tentativas
    ];

    /**
     * Processa a mensagem recebida
     */
    public function work($msg)
    {
        try {
            // Obtém o payload deserializado
            $order = $this->getPayload($msg);
            
            Log::info('Processing order', [
                'order_id' => $order['id'],
                'total' => $order['total']
            ]);

            // ========================================
            // SUA LÓGICA DE NEGÓCIO AQUI
            // ========================================
            
            // Exemplo: Validar pedido
            $this->validateOrder($order);
            
            // Exemplo: Atualizar banco de dados
            $this->saveToDatabase($order);
            
            // Exemplo: Enviar email
            $this->sendConfirmationEmail($order);
            
            // Exemplo: Atualizar estoque
            $this->updateInventory($order);
            
            // ========================================

            Log::info('Order processed successfully', [
                'order_id' => $order['id']
            ]);

            // ✅ SUCESSO - Confirma processamento
            return $this->ack($msg);

        } catch (\InvalidArgumentException $e) {
            // Erro de validação - não vale a pena retentar
            Log::error('Invalid order data', [
                'error' => $e->getMessage(),
                'order' => $order ?? null
            ]);
            
            // ❌ REJEITA - vai direto para DLQ
            return $this->reject($msg, false);

        } catch (\Exception $e) {
            // Erro temporário - vale a pena retentar
            Log::error('Error processing order', [
                'error' => $e->getMessage(),
                'order_id' => $order['id'] ?? 'unknown'
            ]);
            
            // 🔄 NACK - reenfileira para retry
            return $this->nack($msg);
        }
    }

    /**
     * Valida o pedido
     */
    private function validateOrder(array $order): void
    {
        if (empty($order['id'])) {
            throw new \InvalidArgumentException('Order ID is required');
        }

        if (empty($order['items'])) {
            throw new \InvalidArgumentException('Order must have items');
        }

        if ($order['total'] <= 0) {
            throw new \InvalidArgumentException('Order total must be greater than zero');
        }
    }

    /**
     * Salva no banco de dados
     */
    private function saveToDatabase(array $order): void
    {
        // Exemplo:
        // Order::updateOrCreate(
        //     ['id' => $order['id']],
        //     $order
        // );
        
        Log::info('Order saved to database', ['order_id' => $order['id']]);
    }

    /**
     * Envia email de confirmação
     */
    private function sendConfirmationEmail(array $order): void
    {
        // Exemplo:
        // Mail::to($order['customer_email'])
        //     ->send(new OrderConfirmationMail($order));
        
        Log::info('Confirmation email sent', ['order_id' => $order['id']]);
    }

    /**
     * Atualiza estoque
     */
    private function updateInventory(array $order): void
    {
        // Exemplo: decrementar estoque dos produtos
        foreach ($order['items'] as $item) {
            // Product::find($item['product_id'])
            //     ->decrement('stock', $item['quantity']);
        }
        
        Log::info('Inventory updated', ['order_id' => $order['id']]);
    }
}
```

**Pronto!** Isso é tudo que você precisa para criar um worker.

---

## 7. Executar o Worker

### 7.1 Criar Comando Artisan

Crie o arquivo `app/Console/Commands/RabbitMQWorkerCommand.php`:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RabbitMQWorkerCommand extends Command
{
    protected $signature = 'rabbitmq:work {worker}';
    protected $description = 'Run a RabbitMQ worker';

    public function handle()
    {
        $workerClass = "App\\Workers\\{$this->argument('worker')}";

        if (!class_exists($workerClass)) {
            $this->error("Worker class {$workerClass} not found!");
            return 1;
        }

        $this->info("╔══════════════════════════════════════════════╗");
        $this->info("║  🚀 Starting RabbitMQ Worker                ║");
        $this->info("╚══════════════════════════════════════════════╝");
        $this->info("");
        $this->info("Worker: {$workerClass}");
        $this->info("Press Ctrl+C to stop gracefully");
        $this->info("════════════════════════════════════════════════");
        $this->info("");

        try {
            (new $workerClass)->subscribe();
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            return 1;
        }

        return 0;
    }
}
```

### 7.2 Registrar o Comando

Em `app/Console/Kernel.php`, certifique-se de que o comando está registrado:

```php
protected $commands = [
    Commands\RabbitMQWorkerCommand::class,
];
```

### 7.3 Executar o Worker

```bash
php artisan rabbitmq:work OrderProcessorWorker
```

**Saída esperada:**
```
╔══════════════════════════════════════════════╗
║  🚀 Starting RabbitMQ Worker                ║
╚══════════════════════════════════════════════╝

Worker: App\Workers\OrderProcessorWorker
Press Ctrl+C to stop gracefully
════════════════════════════════════════════════

[2025-10-22 10:35:00] Connected to RabbitMQ
[2025-10-22 10:35:00] Waiting for messages...
```

### 7.4 Testar o Fluxo Completo

**Terminal 1 - Worker:**
```bash
php artisan rabbitmq:work OrderProcessorWorker
```

**Terminal 2 - Publicar Evento:**
```bash
curl -X POST http://seu-app.test/api/orders \
  -H "Content-Type: application/json" \
  -d '{
    "customer_name": "Maria Santos",
    "items": [
      {"product": "Mouse", "quantity": 2, "price": 50}
    ],
    "total": 100
  }'
```

**Terminal 1 - Você verá:**
```
[2025-10-22 10:36:15] Processing order (order_id: order_abc123, total: 100)
[2025-10-22 10:36:15] Order saved to database
[2025-10-22 10:36:15] Confirmation email sent
[2025-10-22 10:36:15] Inventory updated
[2025-10-22 10:36:15] Order processed successfully ✅
```

---

## 8. Configurar Supervisor para Produção

### 8.1 Instalar Supervisor

```bash
# Ubuntu/Debian
sudo apt-get install supervisor

# CentOS/RHEL
sudo yum install supervisor
```

### 8.2 Criar Configuração

Crie `/etc/supervisor/conf.d/rabbitmq-workers.conf`:

```ini
[program:rabbitmq-order-processor]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/seu-app/artisan rabbitmq:work OrderProcessorWorker
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=3
redirect_stderr=true
stdout_logfile=/var/www/seu-app/storage/logs/rabbitmq-order-processor.log
stopwaitsecs=60
```

**Explicação:**
- `numprocs=3`: 3 workers em paralelo
- `autostart=true`: Inicia automaticamente
- `autorestart=true`: Reinicia se cair
- `stopwaitsecs=60`: Aguarda 60s no graceful shutdown

### 8.3 Aplicar Configuração

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start rabbitmq-order-processor:*
```

### 8.4 Verificar Status

```bash
sudo supervisorctl status
```

**Saída esperada:**
```
rabbitmq-order-processor:rabbitmq-order-processor_00   RUNNING   pid 12345, uptime 0:01:30
rabbitmq-order-processor:rabbitmq-order-processor_01   RUNNING   pid 12346, uptime 0:01:30
rabbitmq-order-processor:rabbitmq-order-processor_02   RUNNING   pid 12347, uptime 0:01:30
```

### 8.5 Comandos Úteis

```bash
# Parar todos os workers
sudo supervisorctl stop rabbitmq-order-processor:*

# Iniciar todos os workers
sudo supervisorctl start rabbitmq-order-processor:*

# Reiniciar todos os workers
sudo supervisorctl restart rabbitmq-order-processor:*

# Ver logs em tempo real
sudo tail -f /var/www/seu-app/storage/logs/rabbitmq-order-processor.log
```

---

## 9. Monitoramento e Debug

### 9.1 RabbitMQ Management UI

Acesse: `http://localhost:15672`

**O que verificar:**
- **Queues**: Mensagens nas filas
- **Exchanges**: Mensagens publicadas
- **Connections**: Conexões ativas
- **Channels**: Canais abertos

### 9.2 Logs do Laravel

```bash
# Ver logs do RabbitMQ
tail -f storage/logs/laravel.log | grep RabbitMQ

# Ver logs do worker
tail -f storage/logs/rabbitmq-order-processor.log
```

### 9.3 Endpoint de Estatísticas

Adicione em `routes/web.php`:

```php
use Pablicio\MirabelRabbitmq\Facades\RabbitMQ;

Route::get('/rabbitmq/stats', function () {
    return response()->json(RabbitMQ::getStats());
});
```

Acesse: `http://seu-app.test/rabbitmq/stats`

### 9.4 Verificar Dead Letter Queue (DLQ)

```php
use Pablicio\MirabelRabbitmq\Facades\RabbitMQ;

Route::get('/rabbitmq/dlq/{queue}', function ($queue) {
    $dlqManager = RabbitMQ::getDLQManager();
    
    return response()->json([
        'info' => $dlqManager->getDLQInfo("{$queue}.dlq"),
    ]);
});
```

### 9.5 Reprocessar DLQ

```php
Route::post('/rabbitmq/dlq/{queue}/reprocess', function ($queue) {
    $dlqManager = RabbitMQ::getDLQManager();
    
    $reprocessed = $dlqManager->reprocessDLQ(
        "{$queue}.dlq",
        config('rabbitmq.connections.default.exchange'),
        $queue,
        10 // limite de mensagens
    );
    
    return response()->json([
        'reprocessed' => $reprocessed,
    ]);
});
```

---

## 10. Exemplos Completos

### 10.1 Sistema de Pedidos Completo

**Event:**
```php
// app/Events/OrderCreatedEvent.php
namespace App\Events;

use Pablicio\MirabelRabbitmq\Traits\RabbitMQEventsConnection;

class OrderCreatedEvent
{
    use RabbitMQEventsConnection;
    const ROUTING_KEY = 'orders.created';

    public function __construct(array $order)
    {
        $this->routingKey = self::ROUTING_KEY;
        $this->payload = $order;
    }
}
```

**Controller:**
```php
// app/Http/Controllers/OrderController.php
namespace App\Http\Controllers;

use App\Events\OrderCreatedEvent;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        $order = Order::create($request->validated());
        
        (new OrderCreatedEvent($order->toArray()))->publish();
        
        return response()->json($order, 201);
    }
}
```

**Worker:**
```php
// app/Workers/OrderProcessorWorker.php
namespace App\Workers;

use Pablicio\MirabelRabbitmq\Traits\RabbitMQWorkersConnection;

class OrderProcessorWorker
{
    use RabbitMQWorkersConnection;

    const QUEUE = 'orders.processing';
    const ROUTING_KEYS = ['orders.created'];
    const RETRY_OPTIONS = [
        'x-message-ttl' => 5000,
        'max-attempts' => 3
    ];

    public function work($msg)
    {
        try {
            $order = $this->getPayload($msg);
            $this->processOrder($order);
            return $this->ack($msg);
        } catch (\Exception $e) {
            \Log::error('Order processing failed', ['error' => $e->getMessage()]);
            return $this->nack($msg);
        }
    }

    private function processOrder(array $order): void
    {
        // Atualizar estoque
        // Enviar email
        // Gerar nota fiscal
        // etc.
    }
}
```

**Executar:**
```bash
php artisan rabbitmq:work OrderProcessorWorker
```

---

## ✅ Checklist Final

- [ ] RabbitMQ instalado e rodando
- [ ] Biblioteca instalada via Composer
- [ ] Configuração publicada
- [ ] Variáveis de ambiente configuradas
- [ ] Teste de conexão funcionando
- [ ] Primeiro evento criado
- [ ] Evento publicado com sucesso
- [ ] Primeiro worker criado
- [ ] Worker consumindo mensagens
- [ ] Supervisor configurado (produção)
- [ ] Logs funcionando
- [ ] Monitoramento configurado

---

## 🎓 Próximos Passos

1. **Leia a documentação completa**: [LARAVEL_USAGE.md](LARAVEL_USAGE.md)
2. **Veja exemplos avançados**: pasta `examples/Laravel/`
3. **Configure múltiplos workers** para diferentes tipos de eventos
4. **Implemente idempotência** para evitar processamento duplicado
5. **Configure alertas** para DLQ e erros

---

## 📞 Suporte

- **Issues**: [GitHub](https://github.com/pablicio/mirabel-rabbitmq/issues)
- **Email**: pabliciotjg@gmail.com
- **Documentação**: Pasta `docs/`

---

**Pronto!** 🎉 Você agora tem um sistema de mensageria completo e robusto no seu Laravel!
