# 🧪 Guia de Execução de Testes

Este guia mostra como executar os testes e exemplos da biblioteca Mirabel RabbitMQ.

## 📋 Pré-requisitos

### 1. RabbitMQ Instalado e Rodando

**Docker (Recomendado):**
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
# User: guest
# Pass: guest
```

### 2. Dependências do Composer

```bash
composer install
```

## 🧪 Executar Testes Unitários

### Todos os Testes

```bash
# Com PHPUnit
./vendor/bin/phpunit

# Ou com Pest (se configurado)
./vendor/bin/pest
```

### Testes Específicos

```bash
# Apenas testes de Serializers
./vendor/bin/phpunit tests/Unit/Serializers

# Apenas PublisherTest
./vendor/bin/phpunit tests/Unit/PublisherTest.php

# Teste específico
./vendor/bin/phpunit --filter testPublishMessageSuccessfully
```

### Com Coverage

```bash
# Gera relatório de cobertura em HTML
./vendor/bin/phpunit --coverage-html coverage

# Abre o relatório
# Windows:
start coverage/index.html

# Linux/Mac:
open coverage/index.html
# ou
xdg-open coverage/index.html
```

## 🎯 Executar Exemplos

### 1. Exemplo Completo de Funcionalidades

```bash
php examples/complete_examples.php
```

**Saída esperada:**
```
=== Exemplo 1: Configuração Simples ===
Mensagem publicada com sucesso!

=== Exemplo 2: Configuração Avançada ===
Mensagem com opções avançadas publicada!

=== Exemplo 3: Publicação em Lote ===
Publicadas 3 mensagens com sucesso!

[...]
```

### 2. Exemplo de Comunicação entre Serviços

Este exemplo demonstra a comunicação entre ProductService e StoreService.

**Passo 1: Execute o exemplo principal (publica mensagens):**
```bash
php examples/communication_example.php
```

**Passo 2: Em outro terminal, execute o consumer de produtos:**
```bash
php examples/consumer_product.php
```

**Passo 3: Em um terceiro terminal, execute o consumer de lojas:**
```bash
php examples/consumer_store.php
```

## 🔧 Criar os Workers (Consumers)

### Worker do ProductService

Crie o arquivo `examples/consumer_product.php`:

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Services\ProductService;
use Pablicio\MirabelRabbitmq\RabbitMQClientBuilder;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Logger
$logger = new Logger('product-consumer');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

// Cliente RabbitMQ
$rabbitmqClient = RabbitMQClientBuilder::create()
    ->localhost()
    ->logger($logger)
    ->build();

// Serviço
$productService = new ProductService($rabbitmqClient, $logger);

echo "🚀 ProductService Consumer iniciado...\n";
echo "⏳ Aguardando comandos de atualização de estoque...\n";
echo "Press Ctrl+C to stop\n\n";

// Consome mensagens (loop infinito)
$productService->consumeStockUpdateCommands();
```

### Worker do StoreService

Crie o arquivo `examples/consumer_store.php`:

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Services\StoreService;
use Pablicio\MirabelRabbitmq\RabbitMQClientBuilder;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Logger
$logger = new Logger('store-consumer');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

// Cliente RabbitMQ
$rabbitmqClient = RabbitMQClientBuilder::create()
    ->localhost()
    ->logger($logger)
    ->build();

// Serviço
$storeService = new StoreService($rabbitmqClient, $logger);

echo "🚀 StoreService Consumer iniciado...\n";
echo "⏳ Aguardando eventos de produtos...\n";
echo "Press Ctrl+C to stop\n\n";

// Consome mensagens (loop infinito)
$storeService->consumeProductEvents();
```

## 🎬 Fluxo Completo de Teste

### Terminal 1: ProductService Consumer
```bash
php examples/consumer_product.php
```

**Saída esperada:**
```
🚀 ProductService Consumer iniciado...
⏳ Aguardando comandos de atualização de estoque...
Press Ctrl+C to stop

[INFO] Starting to consume stock update commands
```

### Terminal 2: StoreService Consumer
```bash
php examples/consumer_store.php
```

**Saída esperada:**
```
🚀 StoreService Consumer iniciado...
⏳ Aguardando eventos de produtos...
Press Ctrl+C to stop

[INFO] Starting to consume product events
```

### Terminal 3: Executar Exemplo
```bash
php examples/communication_example.php
```

**Saída esperada:**
```
===============================================
=== Exemplo de Comunicação entre Serviços ===
===============================================

--- Cenário 1: Criar Loja ---
[INFO] Creating store
✅ Loja criada com sucesso!

--- Cenário 2: Criar Produto ---
[INFO] Creating product
✅ Produto criado e evento publicado!
   → Loja será notificada via evento 'product.created'

[...]
```

**Nos outros terminais você verá:**

Terminal 1 (ProductService):
```
[INFO] Processing stock update command (attempt: 1)
[INFO] Updating product stock
[INFO] Stock update processed successfully
```

Terminal 2 (StoreService):
```
[INFO] Processing product event (routing_key: product.created)
[INFO] Handling product created
[INFO] Product added to store catalog
```

## 🐛 Troubleshooting

### Erro: "Connection refused"

**Problema:** RabbitMQ não está rodando

**Solução:**
```bash
# Inicie o RabbitMQ
docker start rabbitmq

# Ou inicie um novo container
docker run -d --name rabbitmq -p 5672:5672 -p 15672:15672 rabbitmq:3-management
```

### Erro: "Class not found"

**Problema:** Autoload não atualizado

**Solução:**
```bash
composer dump-autoload
```

### Erro: Testes falhando com "Mock not expected"

**Problema:** Configuração de mock incorreta

**Solução:**
```bash
# Limpe o cache do PHPUnit
rm -rf .phpunit.cache

# Execute novamente
./vendor/bin/phpunit
```

### Mensagens não estão sendo consumidas

**Problema:** Filas podem não estar configuradas ou consumers não estão rodando

**Solução:**
```bash
# Verifique as filas no RabbitMQ Management
http://localhost:15672/#/queues

# Execute os consumers em terminais separados
php examples/consumer_product.php
php examples/consumer_store.php
```

## 📊 Monitoramento durante Testes

### RabbitMQ Management UI

Acesse: `http://localhost:15672`

**O que observar:**
- **Connections**: Número de conexões ativas
- **Channels**: Canais abertos por conexão
- **Queues**: Mensagens nas filas
  - Ready: Prontas para consumo
  - Unacked: Em processamento
- **Exchanges**: Mensagens publicadas

### Logs em Tempo Real

```bash
# Terminal 1: Logs do ProductService
tail -f product-service.log

# Terminal 2: Logs do StoreService
tail -f store-service.log

# Ou veja os logs no stdout dos consumers
```

## 🔍 Validar Resultados

### 1. Verificar Filas Criadas

```bash
# Via Management UI
http://localhost:15672/#/queues

# Ou via CLI (se rabbitmqadmin instalado)
rabbitmqadmin list queues
```

**Filas esperadas:**
- `products.update_stock`
- `products.update_stock.dlq`
- `stores.product_events`
- `stores.product_events.dlq`

### 2. Verificar Exchanges

```bash
# Via Management UI
http://localhost:15672/#/exchanges

# Ou via CLI
rabbitmqadmin list exchanges
```

**Exchanges esperados:**
- `products.events` (topic)
- `products.commands` (direct)
- `stores.events` (topic)

### 3. Verificar Mensagens Processadas

No final do `communication_example.php`, você verá estatísticas:

```
ProductService:
  Conexão: ✅ Conectado
  Host: localhost
  Confirmações pendentes: 0
  Mensagens processadas: 15
  Mensagens ACK: 15
  Erros: 0

StoreService:
  Conexão: ✅ Conectado
  Host: localhost
  Confirmações pendentes: 0
  Mensagens processadas: 12
  Mensagens ACK: 12
  Erros: 0
```

## 🧹 Limpeza após Testes

### Parar Consumers

Pressione `Ctrl+C` em cada terminal dos consumers.

### Limpar Filas

```bash
# Via CLI
rabbitmqadmin purge queue name=products.update_stock
rabbitmqadmin purge queue name=stores.product_events

# Ou via Management UI → Queues → Purge Messages
```

### Parar RabbitMQ

```bash
docker stop rabbitmq

# Ou remover completamente
docker rm -f rabbitmq
```

## 📝 Checklist de Testes

- [ ] RabbitMQ está rodando (porta 5672)
- [ ] Management UI acessível (porta 15672)
- [ ] Dependências instaladas (`composer install`)
- [ ] Testes unitários passando (`./vendor/bin/phpunit`)
- [ ] Exemplo completo executado (`php examples/complete_examples.php`)
- [ ] ProductService consumer rodando
- [ ] StoreService consumer rodando
- [ ] Comunicação entre serviços funcionando
- [ ] Mensagens sendo processadas (verificar Management UI)
- [ ] Estatísticas corretas ao final

## 🚀 Próximos Passos

Após executar os testes com sucesso:

1. **Explore a documentação completa**: `README.md`
2. **Leia o guia de migração**: `MIGRATION.md`
3. **Veja o changelog**: `CHANGELOG.md`
4. **Customize para seu projeto**: Adapte os exemplos para suas necessidades

## 📞 Suporte

Encontrou algum problema?

- **Issues**: [GitHub Issues](https://github.com/pablicio/mirabel-rabbitmq/issues)
- **Email**: pabliciotjg@gmail.com
- **Documentação**: Veja os arquivos na pasta `docs/`

---

✅ **Tudo funcionando?** Você está pronto para usar o Mirabel RabbitMQ em produção!
