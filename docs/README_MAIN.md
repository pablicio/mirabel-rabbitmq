# 🐰 Mirabel RabbitMQ

[![PHP Version](https://img.shields.io/badge/PHP-%5E8.1-blue)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-10%20%7C%2011-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-GPL--3.0-green)](LICENSE)
[![Version](https://img.shields.io/badge/version-2.0.0-orange)](CHANGELOG.md)

**Biblioteca RabbitMQ moderna e robusta para Laravel com abstração total de complexidade.**

```php
// Publicar um evento - simples assim!
(new OrderCreatedEvent($order))->publish();

// Consumir eventos - igualmente simples!
class OrderWorker {
    const QUEUE = 'orders';
    const ROUTING_KEYS = ['orders.created'];
    
    public function work($msg) {
        $order = $this->getPayload($msg);
        processOrder($order);
        return $this->ack($msg);
    }
}
```

---

## ✨ Por Que Usar?

### ❌ Sem Esta Biblioteca
```php
// Você precisa saber:
$connection = new AMQPStreamConnection($host, $port, $user, $pass);
$channel = $connection->channel();
$channel->exchange_declare($exchange, 'topic', false, true, false);
$channel->queue_declare($queue, false, true, false, false);
$channel->queue_bind($queue, $exchange, $routingKey);
$msg = new AMQPMessage($data, ['delivery_mode' => 2]);
$channel->basic_publish($msg, $exchange, $routingKey);
$channel->close();
$connection->close();
// + Gerenciar reconexão, retry, DLQ, etc...
```

### ✅ Com Esta Biblioteca
```php
// Você só precisa:
(new OrderCreatedEvent($order))->publish();
```

**Zero configuração manual. Tudo gerenciado automaticamente.**

---

## 🚀 Instalação Rápida (2 minutos)

```bash
# 1. Instalar
composer require pablicio/mirabel-rabbitmq

# 2. Publicar configuração
php artisan vendor:publish --provider="Pablicio\MirabelRabbitmq\MirabelRabbitmqServiceProvider"

# 3. Configurar .env
RABBITMQ_HOST=localhost
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
```

**Pronto! 🎉** Veja o [guia completo →](STEP_BY_STEP.md)

---

## 📚 Documentação

| Documento | Para Quem | Tempo |
|-----------|-----------|-------|
| **[🚀 STEP_BY_STEP.md](STEP_BY_STEP.md)** | Iniciantes - **COMECE AQUI!** | 30 min |
| [📖 LARAVEL_USAGE.md](LARAVEL_USAGE.md) | Usuários intermediários | 1 hora |
| [📘 DOCUMENTATION_INDEX.md](DOCUMENTATION_INDEX.md) | Todos - Navegação | 5 min |
| [🔬 TESTING.md](TESTING.md) | Desenvolvedores | 30 min |
| [📝 MIGRATION.md](MIGRATION.md) | Migração v1→v2 | 20 min |
| [📊 CHANGELOG.md](CHANGELOG.md) | Histórico | 10 min |

---

## ⚡ Quick Start

### 1. Criar Evento (Publisher)

```php
<?php
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

### 2. Publicar

```php
$order = ['id' => 123, 'total' => 99.90];
(new OrderCreatedEvent($order))->publish();
```

### 3. Criar Worker (Consumer)

```php
<?php
namespace App\Workers;

use Pablicio\MirabelRabbitmq\Traits\RabbitMQWorkersConnection;

class OrderWorker
{
    use RabbitMQWorkersConnection;
    
    const QUEUE = 'orders';
    const ROUTING_KEYS = ['orders.created'];
    const RETRY_OPTIONS = [
        'x-message-ttl' => 5000,
        'max-attempts' => 3
    ];

    public function work($msg)
    {
        $order = $this->getPayload($msg);
        processOrder($order);
        return $this->ack($msg);
    }
}
```

### 4. Executar

```bash
php artisan rabbitmq:work OrderWorker
```

**Veja o [guia completo com exemplos →](STEP_BY_STEP.md)**

---

## 🎯 Características

### ✅ Zero Configuração Manual
- Tudo vem do `.env` e `config/rabbitmq.php`
- Exchanges, queues e bindings criados automaticamente
- Dead Letter Queues configuradas automaticamente

### ✅ Reconexão Automática
- Se o RabbitMQ cair, reconecta automaticamente
- Pool de canais para performance
- Heartbeat gerenciado automaticamente

### ✅ Retry Inteligente
```php
const RETRY_OPTIONS = [
    'x-message-ttl' => 5000,  // 5 segundos entre retries
    'max-attempts' => 3        // 3 tentativas antes de DLQ
];
```

### ✅ Publisher Confirms
- Garantia de que mensagens foram recebidas
- Batch publishing com confirmação
- Timeout configurável

### ✅ Dead Letter Queues
- DLQ criada automaticamente
- Reprocessamento fácil
- Purge controlado

### ✅ Monitoramento
```php
use Pablicio\MirabelRabbitmq\Facades\RabbitMQ;

$stats = RabbitMQ::getStats();
// Conexão, canais, mensagens processadas, erros, etc.
```

### ✅ Graceful Shutdown
- SIGTERM/SIGINT tratados automaticamente
- Mensagem atual finaliza antes de parar
- ACK/NACK apropriado

---

## 🎓 Exemplos

### Publicação Simples
```php
(new UserRegisteredEvent($user))->publish();
```

### Publicação com Prioridade
```php
(new CriticalAlertEvent($alert))->publishUrgent();
```

### Publicação com Delay
```php
(new ReminderEvent($data))->publishDelayed(60000); // 1 minuto
```

### Worker Múltiplos Eventos
```php
class NotificationWorker
{
    use RabbitMQWorkersConnection;
    
    const QUEUE = 'notifications';
    const ROUTING_KEYS = [
        'user.registered',
        'order.created',
        'payment.confirmed'
    ];

    public function work($msg)
    {
        $routingKey = $msg->getRoutingKey();
        $data = $this->getPayload($msg);

        match ($routingKey) {
            'user.registered' => $this->sendWelcomeEmail($data),
            'order.created' => $this->sendOrderConfirmation($data),
            'payment.confirmed' => $this->sendPaymentReceipt($data),
        };

        return $this->ack($msg);
    }
}
```

**Veja [mais exemplos →](LARAVEL_USAGE.md#exemplos-avançados)**

---

## 📦 O Que Está Incluso

### Código
- ✅ Connection Manager com reconexão
- ✅ Channel Pool para performance
- ✅ Confirmed Publisher
- ✅ Consumer com retry
- ✅ Dead Letter Queue Manager
- ✅ Traits para Laravel
- ✅ Facade simplificada

### Documentação
- ✅ 8 guias completos
- ✅ 8 exemplos práticos
- ✅ Passo a passo para iniciantes
- ✅ Casos de uso reais
- ✅ Troubleshooting

### Testes
- ✅ 25 testes unitários
- ✅ Exemplos funcionais
- ✅ Scripts de teste

---

## 🏗️ Arquitetura

```
Sua Aplicação Laravel
├── app/Events/              ← Seus eventos (Publishers)
│   └── OrderCreatedEvent.php
├── app/Workers/             ← Seus workers (Consumers)
│   └── OrderWorker.php
└── config/rabbitmq.php      ← Configuração

Mirabel RabbitMQ (abstração total)
├── Connection Management    ← Gerencia conexões e reconexão
├── Channel Pool            ← Otimiza performance
├── Publisher Confirms      ← Garante entrega
├── Consumer com Retry      ← Retry automático
├── DLQ Manager            ← Dead Letter Queues
└── Traits                 ← API simplificada

RabbitMQ Server            ← Você não precisa saber os detalhes!
```

---

## 🔧 Requisitos

- PHP 8.1+
- Laravel 10 ou 11
- RabbitMQ 3.x
- ext-sockets (opcional, para melhor performance)

---

## 🚀 Produção

### Supervisor
```ini
[program:rabbitmq-workers]
command=php /var/www/artisan rabbitmq:work OrderWorker
autostart=true
autorestart=true
numprocs=3
user=www-data
```

**Veja o [guia completo de produção →](STEP_BY_STEP.md#8-configurar-supervisor-para-produção)**

---

## 📊 Comparação

| Recurso | php-amqplib | bunny/bunny | Esta Biblioteca |
|---------|-------------|-------------|-----------------|
| Instalação | ⚠️ Manual | ⚠️ Manual | ✅ composer require |
| Configuração | ❌ Complexa | ⚠️ Média | ✅ .env |
| Reconexão | ❌ Manual | ⚠️ Manual | ✅ Automática |
| Retry | ❌ Manual | ❌ Manual | ✅ Automático |
| DLQ | ❌ Manual | ❌ Manual | ✅ Automático |
| Laravel | ❌ Não | ❌ Não | ✅ Nativo |
| Curva de aprendizado | ❌ Alta | ⚠️ Média | ✅ Baixa |

---

## 🤝 Contribuindo

Contribuições são bem-vindas!

1. Fork o projeto
2. Crie uma branch (`git checkout -b feature/nova-funcionalidade`)
3. Commit (`git commit -am 'Adiciona nova funcionalidade'`)
4. Push (`git push origin feature/nova-funcionalidade`)
5. Abra um Pull Request

---

## 📜 Licença

GPL-3.0-or-later

---

## 👨‍💻 Autor

**Pablicio**
- Email: pabliciotjg@gmail.com
- GitHub: [@pablicio](https://github.com/pablicio)

---

## 🙏 Agradecimentos

- [php-amqplib](https://github.com/php-amqplib/php-amqplib) - Base AMQP
- Laravel Framework
- Comunidade PHP Brasil

---

## ⭐ Gostou?

Se esta biblioteca te ajudou, dê uma estrela! ⭐

---

## 📞 Suporte

- **Documentação**: [STEP_BY_STEP.md](STEP_BY_STEP.md)
- **Issues**: [GitHub Issues](https://github.com/pablicio/mirabel-rabbitmq/issues)
- **Email**: pabliciotjg@gmail.com

---

<div align="center">

**🐰 Mirabel RabbitMQ v2.0 🐰**

*Mensageria robusta e simples para Laravel*

[📖 Começar](STEP_BY_STEP.md) • [📚 Documentação](DOCUMENTATION_INDEX.md) • [💬 Suporte](mailto:pabliciotjg@gmail.com)

---

Desenvolvido com ❤️ no Brasil 🇧🇷

</div>
