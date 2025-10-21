# Mirabel RabbitMQ - Documentação

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue.svg)](https://php.net)
[![Laravel](https://img.shields.io/badge/laravel-%3E%3D10.0-red.svg)](https://laravel.com)

Cliente RabbitMQ robusto e elegante para Laravel com suporte a retry automático, dead letter queues e múltiplas conexões.

## 📋 Índice

- [Instalação](#instalação)
- [Configuração](#configuração)
- [Uso Básico](#uso-básico)
- [Recursos Avançados](#recursos-avançados)
- [Testes](#testes)
- [Exemplos Práticos](#exemplos-práticos)

## 🚀 Instalação

```bash
composer require pablicio/mirabel-rabbitmq
```

### Publicar Configuração

```bash
php artisan vendor:publish --provider="Pablicio\MirabelRabbitmq\MirabelRabbitmqServiceProvider"
```

### Requisitos

- PHP >= 8.1
- Laravel >= 10.0
- RabbitMQ Server >= 3.8

### Docker Setup (Desenvolvimento)

```bash
docker run -d --hostname my-rabbit --name rabbitmq \
  -p 5672:5672 -p 15672:15672 \
  -e RABBITMQ_DEFAULT_USER=admin \
  -e RABBITMQ_DEFAULT_PASS=secret \
  rabbitmq:3-management
```

Acesse o painel em: http://localhost:15672 (admin/secret)

## ⚙️ Configuração

O arquivo de configuração é publicado em `config/mirabel_rabbitmq.php`:

```php
return [
    'default' => env('RABBITMQ_CONNECTION', 'default'),

    'connections' => [
        'default' => [
            'host' => env('RABBITMQ_HOST', 'localhost'),
            'port' => env('RABBITMQ_PORT', 5672),
            'user' => env('RABBITMQ_USER', 'guest'),
            'password' => env('RABBITMQ_PASSWORD', 'guest'),
            'vhost' => env('RABBITMQ_VHOST', '/'),
            
            'exchange' => [
                'name' => env('RABBITMQ_EXCHANGE', 'laravel'),
                'type' => 'topic',
                'durable' => true,
            ],
            
            'qos' => [
                'prefetch_count' => 1,
            ],
        ],
    ],

    'retry' => [
        'enabled' => true,
        'max_attempts' => 3,
        'initial_delay' => 1000, // ms
    ],
    
    'serializer' => 'json', // 'json' ou 'php'
];
```

### Variáveis de Ambiente

Adicione ao seu `.env`:

```env
RABBITMQ_HOST=localhost
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
RABBITMQ_EXCHANGE=laravel
RABBITMQ_CONNECTION=default
```

## 📖 Uso Básico

### 1. Publicar Mensagens

#### Exemplo Simples

```php
use Pablicio\MirabelRabbitmq\Facades\RabbitMQ;

// Publicar uma mensagem
RabbitMQ::publish('user.created', [
    'user_id' => 123,
    'name' => 'João Silva',
    'email' => 'joao@example.com',
    'created_at' => now(),
]);
```

#### Com Exchange Customizado

```php
RabbitMQ::toExchange('notifications')
    ->publish('email.send', [
        'to' => 'user@example.com',
        'subject' => 'Bem-vindo!',
        'template' => 'welcome',
    ]);
```

#### Com Conexão Específica

```php
RabbitMQ::connection('events')
    ->publish('order.placed', [
        'order_id' => 456,
        'total' => 199.90,
    ]);
```

#### Com Propriedades Customizadas

```php
RabbitMQ::publish('urgent.task', $data, [
    'priority' => 5,
    'expiration' => '60000', // 60 segundos
    'application_headers' => [
        'x-source' => 'api',
        'x-user-id' => auth()->id(),
    ],
]);
```

### 2. Consumir Mensagens

#### Consumer Básico

```php
use Pablicio\MirabelRabbitmq\Facades\RabbitMQ;

RabbitMQ::consume('user-notifications', function ($payload, $message) {
    // Processar mensagem
    $userId = $payload['user_id'];
    
    // Enviar notificação
    Notification::send($userId, new UserNotification($payload));
    
    // Confirmar processamento
    return 'ack';
});
```

#### Com Routing Keys

```php
RabbitMQ::bindKeys(['user.*', 'order.created'])
    ->consume('app-events', function ($payload) {
        Log::info('Event received', $payload);
        return 'ack';
    });
```

#### Com Retry Customizado

```php
RabbitMQ::withRetry(maxAttempts: 5, initialDelay: 2000)
    ->consume('critical-tasks', function ($payload) {
        // Processamento crítico
        ProcessCriticalTask::dispatch($payload);
        return 'ack';
    });
```

#### Controle Manual de Mensagens

```php
RabbitMQ::consume('orders', function ($payload, $message) {
    try {
        processOrder($payload);
        return 'ack'; // Confirma sucesso
    } catch (ValidationException $e) {
        return 'reject'; // Descarta mensagem inválida
    } catch (Exception $e) {
        return 'nack'; // Rejeita e tenta novamente
    }
});
```

**Opções de retorno:**
- `'ack'` ou `true` - Confirma processamento
- `'nack'` - Rejeita e envia para retry
- `'reject'` - Descarta a mensagem
- `'requeue'` - Recoloca na fila imediatamente

### 3. Comandos Artisan

#### Consumir via CLI

```bash
# Consumer simples
php artisan rabbitmq:consume orders

# Com routing keys
php artisan rabbitmq:consume events --keys="user.*,order.*"

# Com conexão específica
php artisan rabbitmq:consume tasks --connection=workers

# Com retry customizado
php artisan rabbitmq:consume critical --max-attempts=5 --retry-delay=3000
```

#### Publicar via CLI

```bash
# Publicar mensagem
php artisan rabbitmq:publish user.created '{"user_id":123,"name":"João"}'

# Para exchange específico
php artisan rabbitmq:publish email.send '{"to":"user@example.com"}' --exchange=notifications

# Com propriedades
php artisan rabbitmq:publish urgent.task '{"id":1}' --priority=5
```

## 🎯 Recursos Avançados

### Múltiplas Conexões

Configure múltiplas conexões em `config/mirabel_rabbitmq.php`:

```php
'connections' => [
    'default' => [
        'host' => 'localhost',
        // ...
    ],
    
    'events' => [
        'host' => 'events.rabbitmq.local',
        'exchange' => [
            'name' => 'events',
            'type' => 'topic',
        ],
    ],
    
    'workers' => [
        'host' => 'workers.rabbitmq.local',
        'qos' => [
            'prefetch_count' => 10,
        ],
    ],
],
```

Uso:

```php
// Publicar em conexões diferentes
RabbitMQ::connection('events')->publish('user.login', $data);
RabbitMQ::connection('workers')->publish('process.video', $data);

// Consumir de conexão específica
RabbitMQ::connection('workers')
    ->consume('video-processing', $callback);
```

### Retry Automático e Dead Letter Queue

O pacote implementa automaticamente retry com backoff exponencial:

```
Message → Main Queue → Error → Retry Queue (delay) → Main Queue
                ↓ (max attempts)
           Dead Letter Queue
```

**Configuração:**

```php
'retry' => [
    'enabled' => true,
    'max_attempts' => 3,
    'initial_delay' => 1000, // 1 segundo
],

'dead_letter' => [
    'enabled' => true,
],
```

**Como funciona:**
1. Mensagem falha no processamento
2. É enviada para fila de retry com delay
3. Após delay, retorna à fila principal
4. Se exceder max_attempts, vai para Dead Letter Queue

### Serialização

#### JSON (Padrão)

```php
'serializer' => 'json',
```

Melhor para:
- Interoperabilidade
- Debug fácil
- Tamanho moderado

#### PHP Nativo

```php
'serializer' => 'php',
```

Melhor para:
- Objetos PHP complexos
- Performance
- Uso exclusivo em PHP

### Logging

Configure logs detalhados:

```php
'logging' => [
    'enabled' => true,
    'channel' => env('RABBITMQ_LOG_CHANNEL', 'stack'),
    'level' => env('RABBITMQ_LOG_LEVEL', 'info'),
],
```

Os logs incluem:
- Publicações e consumos
- Erros e exceções
- Retry attempts
- Performance metrics

## 🧪 Testes

### Executar Testes Unitários

```bash
composer test
```

ou

```bash
./vendor/bin/phpunit
```

### Executar Testes Específicos

```bash
./vendor/bin/phpunit tests/Unit/PublisherTest.php
./vendor/bin/phpunit tests/Unit/ConsumerTest.php
./vendor/bin/phpunit tests/Unit/Serializers/JsonSerializerTest.php
```

### Coverage

```bash
./vendor/bin/phpunit --coverage-html coverage
```

## 📚 Exemplos Práticos

### Exemplo 1: Sistema de Notificações

```php
// Publicar evento de novo usuário
use App\Models\User;
use Pablicio\MirabelRabbitmq\Facades\RabbitMQ;

class UserController extends Controller
{
    public function store(Request $request)
    {
        $user = User::create($request->validated());
        
        // Publicar evento
        RabbitMQ::publish('user.created', [
            'user_id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'created_at' => $user->created_at->toIso8601String(),
        ]);
        
        return response()->json($user, 201);
    }
}
```

```php
// Consumer de notificações
// php artisan rabbitmq:consume user-notifications --keys="user.*"

use Illuminate\Support\Facades\Mail;
use Pablicio\MirabelRabbitmq\Facades\RabbitMQ;

RabbitMQ::bindKeys(['user.created', 'user.updated'])
    ->consume('user-notifications', function ($payload) {
        $user = User::find($payload['user_id']);
        
        if ($payload['event'] === 'user.created') {
            Mail::to($user)->send(new WelcomeEmail($user));
        }
        
        return 'ack';
    });
```

### Exemplo 2: Processamento de Pedidos

```php
// Publicar pedido para processamento
class OrderService
{
    public function placeOrder(array $items, User $user): Order
    {
        DB::transaction(function () use ($items, $user) {
            $order = Order::create([
                'user_id' => $user->id,
                'total' => $this->calculateTotal($items),
                'status' => 'pending',
            ]);
            
            $order->items()->createMany($items);
            
            // Publicar para processamento assíncrono
            RabbitMQ::publish('order.placed', [
                'order_id' => $order->id,
                'user_id' => $user->id,
                'total' => $order->total,
                'items_count' => count($items),
            ]);
            
            return $order;
        });
    }
}
```

```php
// Consumer de pedidos
RabbitMQ::withRetry(maxAttempts: 5, initialDelay: 2000)
    ->consume('order-processing', function ($payload) {
        $order = Order::find($payload['order_id']);
        
        // Processar pagamento
        $payment = PaymentGateway::charge($order);
        
        if ($payment->successful()) {
            $order->update(['status' => 'paid']);
            
            // Publicar próximo evento
            RabbitMQ::publish('order.paid', [
                'order_id' => $order->id,
            ]);
            
            return 'ack';
        }
        
        // Retry em caso de falha temporária
        return 'nack';
    });
```

### Exemplo 3: Upload e Processamento de Arquivos

```php
// Controller de upload
class FileController extends Controller
{
    public function upload(Request $request)
    {
        $path = $request->file('document')->store('uploads');
        
        $file = File::create([
            'user_id' => auth()->id(),
            'path' => $path,
            'status' => 'pending',
        ]);
        
        // Enviar para processamento
        RabbitMQ::toExchange('files')
            ->publish('file.uploaded', [
                'file_id' => $file->id,
                'path' => $path,
                'mime_type' => $request->file('document')->getMimeType(),
            ], [
                'priority' => 3,
            ]);
        
        return response()->json($file);
    }
}
```

```php
// Consumer de arquivos
RabbitMQ::connection('workers')
    ->withRetry(3, 5000)
    ->consume('file-processing', function ($payload) {
        $file = File::find($payload['file_id']);
        
        try {
            // Processar arquivo (OCR, thumbnails, etc)
            $processor = new FileProcessor($file);
            $result = $processor->process();
            
            $file->update([
                'status' => 'processed',
                'metadata' => $result,
            ]);
            
            // Notificar usuário
            RabbitMQ::publish('notification.send', [
                'user_id' => $file->user_id,
                'message' => 'Seu arquivo foi processado com sucesso!',
            ]);
            
            return 'ack';
            
        } catch (ProcessingException $e) {
            Log::error('File processing failed', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
            
            $file->update(['status' => 'failed']);
            return 'reject'; // Não retentar
        }
    });
```

### Exemplo 4: Event Sourcing

```php
// Publicar eventos de domínio
class BankAccount
{
    public function withdraw(float $amount): void
    {
        if ($this->balance < $amount) {
            throw new InsufficientFundsException();
        }
        
        $this->balance -= $amount;
        
        // Publicar evento
        RabbitMQ::toExchange('events')
            ->publish('account.withdrew', [
                'account_id' => $this->id,
                'amount' => $amount,
                'balance' => $this->balance,
                'timestamp' => now()->toIso8601String(),
            ]);
    }
}
```

```php
// Projeções e read models
RabbitMQ::bindKeys(['account.*'])
    ->consume('account-projection', function ($payload) {
        match ($payload['event']) {
            'account.created' => AccountProjection::create($payload),
            'account.deposited' => AccountProjection::updateBalance($payload),
            'account.withdrew' => AccountProjection::updateBalance($payload),
            default => null,
        };
        
        return 'ack';
    });
```

## 🔧 Troubleshooting

### Conexão recusada

```bash
# Verificar se RabbitMQ está rodando
docker ps | grep rabbitmq

# Ver logs do RabbitMQ
docker logs rabbitmq
```

### Mensagens não consumidas

```bash
# Verificar filas
rabbitmqctl list_queues

# Limpar fila específica
rabbitmqctl purge_queue queue-name
```

### Dead Letter Queue crescendo

Verifique os logs para identificar problemas recorrentes:

```php
// Consumir DLQ para análise
RabbitMQ::consume('queue-name.error', function ($payload) {
    Log::error('DLQ Message', $payload);
    return 'ack';
});
```

## 📝 Licença

MIT License - veja o arquivo LICENSE para detalhes.

## 🤝 Contribuindo

Contribuições são bem-vindas! Por favor:

1. Faça fork do projeto
2. Crie uma branch para sua feature (`git checkout -b feature/amazing`)
3. Commit suas mudanças (`git commit -m 'Add amazing feature'`)
4. Push para a branch (`git push origin feature/amazing`)
5. Abra um Pull Request

## 📞 Suporte

- Issues: [GitHub Issues](https://github.com/pablicio/mirabel-rabbitmq/issues)
- Email: suporte@example.com

---

**Desenvolvido com ❤️ por Pablicio**
