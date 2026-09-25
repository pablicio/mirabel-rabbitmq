# Mirabel RabbitMQ v2 — o ganho na prática

> Mesmo cenário do artigo original do Thiago Pablicio (Order Service + Store Service trocando eventos via RabbitMQ), reproduzido com a v2 proposta. O objetivo aqui é mostrar, lado a lado, exatamente o que muda pro dev que usa e o que muda por baixo — sem o resto da auditoria no meio.
>
> **Atualização:** existem hoje (set/2026) pacotes Laravel+RabbitMQ maduros no mercado com DX comparável. A Seção 0 compara os dois mais relevantes com exemplos reais, antes do resto do documento.

---

## 0. Existe algo parecido hoje? Sim — comparação honesta

Pesquisei o estado atual do Packagist/GitHub. Ignorando os clones antigos e abandonados de `bschmitt/laravel-amqp` (forks de 2016 sem manutenção, tipo `mmanazar/laravel-amqp`, `nassajis/laravel-amqp`), sobram dois concorrentes reais que valem comparar:

### 0.1 `bschmitt/laravel-amqp` — o veterano, hoje muito mais robusto

É o pacote mais antigo e estabelecido do nicho (existe desde ~2015), e continua ativamente mantido — a versão atual já inclui RPC, retry declarativo com backoff, DLQ topology, validação de schema e métricas. Não é mais o wrapper simples que era.

```php
use Bschmitt\Amqp\Facades\Amqp;

// Publicar
Amqp::publish('order-services.order.received', json_encode(['id' => 123]));

// Consumir
Amqp::consume('order-services.order-test.done', function ($message, $resolver) {
    print_r($message->body);
    $resolver->acknowledge($message);
});
```

Retry/DLQ declarativo (versão recente):

```php
use Bschmitt\Amqp\Support\RetryPolicy;
use Bschmitt\Amqp\Support\DeadLetterTopology;

$retry = RetryPolicy::exponential(maxAttempts: 8, baseDelayMs: 1000, cap: 30000, jitter: true);
$topology = DeadLetterTopology::forQueue('order-services.order-test.done', $retry);

Amqp::consume('order-services.order-test.done', $handler, $topology->toConsumeOptions());
```

**Onde ganha do Mirabel v2 proposto:** já existe, já é testado em produção por muita gente, tem RPC pronto (`Amqp::rpc()`), tem `RetryPolicy` com backoff exponencial + jitter nativo (o Mirabel v2 documenta backoff exponencial como "múltiplas filas na mão", não como uma classe pronta), tem validação de schema e métricas de RPC integradas a eventos Laravel (`RpcCallCompleted`).

**Onde o Mirabel v2 ganharia:** a API é **baseada em closures e strings de routing key soltas** (`Amqp::consume('queue-name', function ($message, $resolver) {...})`), não em classes com identidade própria. Não há uma classe `OrderReceivedEvent` que você instancia e reusa — cada publish/consume é uma chamada solta na Facade, geralmente dentro de um Artisan Command escrito à mão. Funciona bem, mas não tem o efeito "a classe é a documentação" do artigo original. E continua sendo **Laravel-only no Core** — não tem (nem promete) uso framework-agnostic.

### 0.2 `lettermint/laravel-rabbitmq` — o mais parecido em filosofia

Esse é o comparável mais direto ao que você quer, porque a ideia central também é "a classe carrega a identidade declarativamente" — só que usando **atributos PHP 8** em cima de **Jobs nativos do Laravel**, em vez de herança de classe própria:

```php
<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Lettermint\RabbitMQ\Attributes\ConsumesQueue;

#[ConsumesQueue(
    queue: 'emails',
    bindings: ['notifications' => 'email.*'],
    quorum: true,
    retryAttempts: 3,
)]
class SendEmailJob implements ShouldQueue
{
    use Dispatchable;

    public function __construct(
        public string $email,
        public string $subject,
        public string $message,
    ) {}

    public function handle(): void
    {
        Mail::to($this->email)->send(new NotificationMail($this->subject, $this->message));
    }
}
```

```php
// Declarar a topologia (exchange/queue/binding) a partir do atributo
php artisan rabbitmq:declare

// Publicar — dispatch() nativo do Laravel, sem API nova pra aprender
SendEmailJob::dispatch($email, $subject, $message)->onConnection('rabbitmq');

// Consumir
php artisan rabbitmq:consume emails --connection=rabbitmq --tries=3 --timeout=60
```

**Onde ganha do Mirabel v2 proposto:**
- **Zero curva de aprendizado pra quem já usa Laravel Queue** — é literalmente `ShouldQueue::dispatch()`, o mesmo verbo que todo dev Laravel já usa pra Database/Redis/SQS. O Mirabel v2 introduz um verbo novo (`->publish()`/`->subscribe()`), que é elegante mas é **mais um conceito pra aprender**, mesmo que pequeno.
- **DLQ com ferramentas de operação prontas**: `rabbitmq:dlq-inspect`, `rabbitmq:replay-dlq`, `rabbitmq:dlq-purge`, com página Filament opcional pra inspecionar/reprocessar falhas visualmente. Isso é bem além do que o Mirabel v2 planeja pro Core.
- **Health/probe/audit prontos pra Kubernetes**: `rabbitmq:health`, `rabbitmq:probe --wait=60`, arquivo de status por worker via `RABBITMQ_WORKER_STATUS_FILE` — cobre exatamente o item "Health checks" que a auditoria do Mirabel listava como responsabilidade da aplicação, só que já pronto.
- **Quorum queues, delayed messages nativos do RabbitMQ, prioridade 0–255** já suportados como parâmetros do atributo — o Mirabel v2 lista isso como "fase avançada", não MVP.
- Documenta honestamente **at-least-once delivery** logo na primeira linha do README — mesma postura que a auditoria do Mirabel recomenda.

**Onde o Mirabel v2 ainda ganharia:**
- **Framework-agnostic.** Esse é o ponto que continua não tendo resposta no mercado: `lettermint/laravel-rabbitmq` depende de `ShouldQueue`, `Dispatchable`, todo o sistema de Queue do Illuminate — é, por design, indissociável do Laravel. Não roda em PHP puro, não roda em Symfony. Zero dos concorrentes pesquisados resolve isso.
- **Maturidade real:** o pacote tem 3 estrelas, 1 fork, é descrito no próprio README do GitHub como "an experimental implementation of RabbitMQ concepts in Laravel" — é uma ideia nova e bem feita, não uma escolha testada em produção por uma comunidade grande ainda. Isso pode mudar rápido, mas hoje é um fator de risco real de adotar.
- **A elegância de "uma classe = evento, sem herdar sistema de Queue inteiro"** continua sendo diferente: um `Job` do Laravel carrega serialização, middleware, chains, batches — todo o peso do sistema de Queue do Illuminate por baixo. O Mirabel v2 (`extends Event`/`extends Worker`) é deliberadamente mais fino: não é uma Job, é um evento de mensageria puro, sem se acoplar ao sistema de Queue do framework.

### 0.3 Veredito atualizado

O vácuo específico que a auditoria do Mirabel apontava — **framework-agnostic + DX declarativa + retry correto, tudo junto** — **continua vazio**. `bschmitt/laravel-amqp` tem a robustez mas não a DX de classe-única nem agnosticismo. `lettermint/laravel-rabbitmq` tem quase toda a DX e robustez operacional que o Mirabel v2 quer, mas é 100% amarrado ao Laravel Queue e ainda é experimental.

Isso muda a recomendação prática:

- **Se você só precisa disso rodando em Laravel, hoje, sem esperar nada nascer**: `lettermint/laravel-rabbitmq` é a opção mais próxima do que você descreveu como ideal — vale testar antes de escrever uma linha do Mirabel v2, mesmo que só pra roubar ideias de execução (o CLI de DLQ replay/inspect, o health/probe pra K8s, os atributos de quorum/priority/delay).
- **Se `bschmitt/laravel-amqp` já resolve** (RPC, retry, schema validation) e framework-agnostic não é requisito seu, é a opção mais madura e testada — ponto final, sem precisar construir nada.
- **O Mirabel v2 só vale a pena construir se o requisito "funciona fora do Laravel" for real e não-negociável.** Se for só Laravel mesmo, hoje, dois pacotes prontos já cobrem praticamente tudo que a auditoria pedia — e um deles (`lettermint`) com DX bem perto da que você quer.

---

## 1. O ganho, em uma frase

**v1:** a mesma sintaxe elegante, mas com um motor que quebra sob qualquer estresse real (reconexão, contagem de retry, Laravel obrigatório).
**v2:** a mesma sintaxe elegante — praticamente idêntica, char por char — só que agora funciona fora do Laravel, sobrevive a reconexão, e conta retry certo porque usa o header nativo do RabbitMQ em vez de uma variável que se perde.

Você não troca DX por robustez. É esse o ponto do redesenho: o dev que já usava v1 não perde nada de simplicidade — ganha só o que estava faltando.

---

## 2. Lado a lado: o que muda no código de negócio

### Evento (Producer)

```php
// v1 — artigo original
<?php
namespace App\Events;

use Pablicio\MirabelRabbitmq\RabbitMQEventsConnection;

class OrderReceivedEvent
{
    use RabbitMQEventsConnection;

    const ROUTING_KEY = 'order-services.order.received';

    function __construct($payload)
    {
        $this->routingKey = self::ROUTING_KEY;
        $this->payload = $payload;
    }
}
```

```php
// v2
<?php
namespace App\Events;

use Mirabel\RabbitMQ\Event;

class OrderReceivedEvent extends Event
{
    public static string $routingKey = 'order-services.order.received';

    public function __construct(public mixed $payload) {}
}
```

**O que mudou:** `use trait` virou `extends Event` (uma dependência explícita de herança, não uma composição escondida). `const` + atribuição manual no construtor virou uma `public static` tipada — mesma quantidade de linhas, mas agora o PHP valida o tipo e não existe mais `defined('self::X')` checando se a constante existe. O construtor virou property promotion (PHP 8), cortando uma linha.

**Uso — idêntico:**
```php
(new App\Events\OrderReceivedEvent('Received'))->publish();
```

### Worker (Consumer)

```php
// v1 — artigo original
<?php
namespace App\Workers;

use Pablicio\MirabelRabbitmq\RabbitMQWorkersConnection;

class TestOrderDoneWorker
{
    use RabbitMQWorkersConnection;

    const QUEUE = 'order-services.order-test.done',
        routing_keys = ['test-service.order.done'],
        options = ['exchange_type' => 'topic'],
        retry_options = ['x-message-ttl' => 1000, 'max-attempts' => 8];

    public function work($msg)
    {
        try {
            print_r($msg->body);
            return $this->ack($msg);
        } catch (\Exception $e) {
            return $this->nack($msg);
        }
    }
}
```

```php
// v2
<?php
namespace App\Workers;

use Mirabel\RabbitMQ\Worker;
use Mirabel\RabbitMQ\Envelope;

class TestOrderDoneWorker extends Worker
{
    public static string $queue = 'order-services.order-test.done';
    public static array $routingKeys = ['test-service.order.done'];
    public static array $retry = ['delay' => 1000, 'max_attempts' => 8];

    public function handle(Envelope $envelope): void
    {
        print_r($envelope->body);
        $envelope->ack();
    }
}
```

**O que mudou:**
- `work($msg)` virou `handle(Envelope $envelope)` — `$envelope` já vem desserializado (JSON por padrão) e carrega `ack()`/`nack()`/`reject()` nele mesmo, em vez de precisar de um trait `RabbitMQHelpers` à parte.
- Não precisa mais do `try/catch` manual para decidir ack/nack — se o `handle()` lançar exceção sem chamar `nack()` explicitamente, o Mirabel v2 já intercepta e trata como falha, entrando no fluxo de retry sozinho. Você ainda **pode** fazer o `try/catch` se quiser controle fino (nack com `requeue: false` num caso específico, por exemplo), mas não é obrigatório pro caso comum.
- `max-attempts` no v1 nunca funcionava direito (bug do contador local ao processo). No v2, `max_attempts` é lido do header `x-death` do próprio RabbitMQ — sobrevive a restart do worker, a múltiplas réplicas, a tudo.

**Uso — idêntico:**
```php
(new App\Workers\TestOrderDoneWorker)->subscribe();
```

### Configuração

```php
// v1 — config/mirabel_rabbitmq.php gerado por vendor:publish, lido via config() do Laravel
'host' => env('MB_RABBITMQ_HOST', 'localhost'),
```

```env
# v2 — mesmas variáveis, mas lidas via getenv() puro do PHP, sem exigir Laravel
MB_RABBITMQ_HOST=localhost
MB_RABBITMQ_PORT=5672
MB_RABBITMQ_USER=guest
MB_RABBITMQ_PASSWORD=guest
MB_RABBITMQ_EXCHANGE=my-exchange
```

**O que mudou:** mesmas variáveis `MB_RABBITMQ_*`, mesmo `.env`. A diferença é que no v1 elas só chegam até o código porque o Laravel resolve `env()` → `config()` → `config('mirabel_rabbitmq.connections.rabbitmq-php.host')`. No v2, a lib lê `getenv('MB_RABBITMQ_HOST')` direto — funciona em PHP puro, num script CLI solto, dentro do Symfony, em qualquer lugar. Não existe `vendor:publish`, não existe arquivo de config pra gerar.

### Tabela-resumo do ganho real

| Situação | v1 | v2 |
|---|---|---|
| Rodar fora do Laravel | Impossível | Funciona nativamente |
| Conexão cai no meio do consumo | Worker morre | Reconecta com backoff |
| Duas mensagens em sequência, contagem de retry | Contador compartilhado (bug) | Contador por mensagem via `x-death` |
| `docker stop` no worker | Mata no meio do processamento | Termina a mensagem atual, sai limpo |
| Publicar mensagem, broker cair antes do ack | Sem garantia nenhuma | `confirm_select()` opcional |
| Erro de digitação em `const options` não declarada | Fatal error silencioso | Erro de tipo em tempo de desenvolvimento (IDE já acusa) |
| Trocar de framework (Laravel → Symfony → nada) | Reescrever tudo | Trocar só o pacote de integração |

---

## 3. Tutorial — instalação e implementação realista

Mesmo cenário do artigo: dois serviços, **Order Service** e **Store Service**, trocando eventos via RabbitMQ. Vou fazer em **PHP puro primeiro** (pra mostrar o ganho central: zero Laravel) e depois a variante Laravel (pra quem já está no ecossistema e quer a mesma DX com Artisan).

> Assumindo que o pacote já existe publicado como `mirabel/rabbitmq` (Core) e `mirabel/rabbitmq-laravel` (integração), conforme definido na auditoria.

### 3.1 Pré-requisitos

- PHP 8.2+ (`php -v` pra conferir)
- Composer
- Docker (pra subir o RabbitMQ local)

### 3.2 Subindo o RabbitMQ

```bash
docker run -it --rm --name rabbitmq \
  -p 5672:5672 -p 15672:15672 \
  rabbitmq:4-management
```

Acesse `http://localhost:15672` (usuário/senha: `guest`/`guest`) pra ver filas e exchanges sendo criadas conforme os workers sobem.

### 3.3 Criando o projeto — PHP puro (Order Service)

```bash
mkdir order-service && cd order-service
composer init --no-interaction --name="acme/order-service" --require="php:^8.2"
composer require mirabel/rabbitmq
```

Estrutura mínima:

```
order-service/
├── .env
├── composer.json
├── src/
│   ├── Events/
│   │   └── OrderReceivedEvent.php
│   └── Workers/
│       └── TestOrderDoneWorker.php
├── bin/
│   └── consume.php
└── vendor/
```

`.env`:
```env
MB_RABBITMQ_HOST=localhost
MB_RABBITMQ_PORT=5672
MB_RABBITMQ_USER=guest
MB_RABBITMQ_PASSWORD=guest
MB_RABBITMQ_EXCHANGE=my-exchange
MB_RABBITMQ_EXCHANGE_TYPE=topic
```

Não tem `vendor:publish`, não tem service provider pra registrar. A lib lê o `.env` sozinha (via `getenv()` — você precisa garantir que as variáveis estão no ambiente do processo; em PHP puro isso geralmente é feito com `vlucas/phpdotenv` carregando o `.env` no bootstrap, igual qualquer app PHP moderna faria).

`bin/consume.php` (bootstrap mínimo):
```php
<?php
require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
```

`src/Events/OrderReceivedEvent.php`:
```php
<?php
namespace Acme\OrderService\Events;

use Mirabel\RabbitMQ\Event;

class OrderReceivedEvent extends Event
{
    public static string $routingKey = 'order-services.order.received';

    public function __construct(public mixed $payload) {}
}
```

`src/Workers/TestOrderDoneWorker.php`:
```php
<?php
namespace Acme\OrderService\Workers;

use Mirabel\RabbitMQ\Worker;
use Mirabel\RabbitMQ\Envelope;

class TestOrderDoneWorker extends Worker
{
    public static string $queue = 'order-services.order-test.done';
    public static array $routingKeys = ['test-service.order.done'];
    public static array $retry = ['delay' => 1000, 'max_attempts' => 8];

    public function handle(Envelope $envelope): void
    {
        echo "Recebido: " . print_r($envelope->body, true) . "\n";
        $envelope->ack();
    }
}
```

Publicando um evento — script solto ou tinker-like via `php -a`, sem framework nenhum:
```php
<?php
// bin/publish-example.php
require __DIR__ . '/../vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(__DIR__ . '/..'))->load();

use Acme\OrderService\Events\OrderReceivedEvent;

(new OrderReceivedEvent('Received'))->publish();
echo "Publicado.\n";
```

```bash
php bin/publish-example.php
```

Consumindo — o worker roda como processo de longa duração:
```php
<?php
// bin/consume-test-order-done.php
require __DIR__ . '/../vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(__DIR__ . '/..'))->load();

use Acme\OrderService\Workers\TestOrderDoneWorker;

(new TestOrderDoneWorker)->subscribe();
```

```bash
php bin/consume-test-order-done.php
```

Isso já é o Order Service completo, rodando **sem Laravel, sem Symfony, sem nada além de PHP + Composer + RabbitMQ** — o que era literalmente impossível com o v1 (o `config()` hardcoded quebrava fora do container Laravel).

### 3.4 Store Service — mesma estrutura, outro processo

```bash
mkdir store-service && cd store-service
composer init --no-interaction --name="acme/store-service" --require="php:^8.2"
composer require mirabel/rabbitmq
```

`src/Workers/StoreOrderReceivedWorker.php`:
```php
<?php
namespace Acme\StoreService\Workers;

use Mirabel\RabbitMQ\Worker;
use Mirabel\RabbitMQ\Envelope;

class StoreOrderReceivedWorker extends Worker
{
    public static string $queue = 'store-services.orders.received';
    public static array $routingKeys = ['order-services.order.received'];
    public static array $retry = ['delay' => 3600, 'max_attempts' => 4];

    public function handle(Envelope $envelope): void
    {
        if ($envelope->body === 'test') {
            throw new \RuntimeException('Falha simulada');
            // não precisa de try/catch aqui — o Mirabel intercepta,
            // conta a tentativa via x-death e decide retry/DLQ sozinho
        }

        echo "Deu bom: " . $envelope->body . "\n";
        $envelope->ack();
    }
}
```

`src/Events/StoreOrderCreatedEvent.php`:
```php
<?php
namespace Acme\StoreService\Events;

use Mirabel\RabbitMQ\Event;

class StoreOrderCreatedEvent extends Event
{
    public static string $routingKey = 'store-services.order.created';

    public function __construct(public mixed $payload) {}
}
```

Rodando:
```bash
php bin/consume-store-order-received.php   # sobe o worker
php bin/publish-store-order-created.php    # dispara o evento
```

### 3.5 Variante Laravel — pra quem já está no ecossistema

Se seus dois serviços já são apps Laravel (como no artigo original), a única diferença prática é o pacote de integração e o bootstrap — **o código de `Events/` e `Workers/` não muda uma linha**:

```bash
composer create-project laravel/laravel order-service
cd order-service
composer require mirabel/rabbitmq-laravel
php artisan vendor:publish --tag=mirabel-config
```

Isso publica `config/mirabel.php` (o `ServiceProvider` traduz a config Laravel pro `ConnectionConfig` do Core por baixo — o Core continua nunca vendo `env()` do Laravel diretamente). `.env` com as mesmas `MB_RABBITMQ_*`.

```bash
php artisan tinker
```

```php
(new App\Events\OrderReceivedEvent('Received'))->publish();
(new App\Workers\TestOrderDoneWorker)->subscribe();
```

Idêntico ao artigo original — inclusive tem um Artisan command pronto pra produção, em vez de rodar `subscribe()` dentro do tinker:

```bash
php artisan mirabel:consume order-services.order-test.done
```

Isso resolve o problema real de produção que o `tinker` tem (não é feito pra processo de longa duração supervisionado) sem você precisar escrever esse command na mão.

### 3.6 Testando o fluxo de retry/DLQ de verdade

Publique algo que force falha (`'test'`, no exemplo do `StoreOrderReceivedWorker`) e observe no painel do RabbitMQ (`localhost:15672` → Queues):

```
store-services.orders.received          (fila normal)
store-services.orders.received.retry    (TTL 3600ms, volta pra normal)
store-services.orders.received.error    (DLQ final, após 4 tentativas)
```

A mensagem vai navegar `normal → retry → normal → retry → ... → error`, e cada tentativa é contada pelo próprio RabbitMQ (`x-death.count`), não por uma variável PHP que se perde se o worker reiniciar no meio — que era exatamente o bug crítico do v1.

---

## 4. O que isso NÃO resolve sozinho

Pra manter isso honesto: o Mirabel v2 não é mágica. Ele não substitui:
- **Idempotência real** — RabbitMQ com ack manual é *at-least-once*, nunca *exactly-once*. Se seu handler não for idempotente, uma redelivery vai reprocessar. O `Envelope` expõe `messageId` pra você implementar deduplicação na sua aplicação, mas isso é responsabilidade sua, não da lib.
- **Escala horizontal** — subir mais réplicas do worker é papel do seu orquestrador (Kubernetes, supervisor), não da lib.
- **Observabilidade completa** — você ainda precisa plugar um logger PSR-3 pra ter rastro de falhas; o Mirabel não inventa isso sozinho, só expõe os hooks.

Isso é intencional: o ganho é robustez de infraestrutura de mensageria, não terceirização de decisões de arquitetura da sua aplicação.