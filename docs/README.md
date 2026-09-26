# Mirabel RabbitMQ

Biblioteca PHP framework-agnostic para publicar e consumir mensagens RabbitMQ
com uma API curta, declarativa e baseada em `php-amqplib`.

> Esta documentação descreve o comportamento implementado no código atual.
> Recursos ainda não implementados são identificados explicitamente na seção
> de limitações.

## Sumário

- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Configuração](#configuração)
- [Publicando eventos](#publicando-eventos)
- [Consumindo mensagens](#consumindo-mensagens)
- [API clássica](#api-clássica)
- [API com Envelope](#api-com-envelope)
- [Retry e fila de erro](#retry-e-fila-de-erro)
- [Semântica de entrega](#semântica-de-entrega)
- [Testes](#testes)
- [Uso com Laravel](#uso-com-laravel)
- [Outbox transacional](#outbox-transacional)
- [Extensão e testes unitários](#extensão-e-testes-unitários)
- [Observabilidade](#observabilidade)
- [Limitações atuais](#limitações-atuais)

## Requisitos

- PHP 8.2 ou superior.
- Composer.
- RabbitMQ 3.8 ou superior. RabbitMQ 4 é suportado pela topologia atual.
- Extensões PHP `sockets` e `mbstring`, exigidas pelo `php-amqplib`.

A biblioteca não depende de Laravel, Symfony ou qualquer container de aplicação.

## Instalação

```bash
composer require mirabel/rabbitmq
```

A dependência AMQP utilizada pelo Core é `php-amqplib/php-amqplib:^3.7`.

Para desenvolver contra um checkout local da biblioteca, adicione um repositório
`path` ao projeto consumidor:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../mirabel-rabbitmq",
      "options": { "symlink": true }
    }
  ],
  "require": {
    "mirabel/rabbitmq": "dev-master"
  }
}
```

Depois execute:

```bash
composer update mirabel/rabbitmq php-amqplib/php-amqplib
```

## Configuração

A configuração é lida de forma lazy, na primeira publicação ou inscrição, por
`getenv()`. O Core não usa `env()` nem `config()` do Laravel.

```env
MB_RABBITMQ_HOST=127.0.0.1
MB_RABBITMQ_PORT=5672
MB_RABBITMQ_USER=guest
MB_RABBITMQ_PASSWORD=guest
MB_RABBITMQ_VHOST=/
MB_RABBITMQ_EXCHANGE=my-exchange
MB_RABBITMQ_EXCHANGE_TYPE=topic
MB_RABBITMQ_PUBLISHER_CONFIRMS=false
MB_RABBITMQ_CONNECT_TIMEOUT=3
MB_RABBITMQ_READ_WRITE_TIMEOUT=3
MB_RABBITMQ_HEARTBEAT=30
MB_RABBITMQ_RECONNECT_ATTEMPTS=0
MB_RABBITMQ_RECONNECT_DELAY_MS=1000
MB_RABBITMQ_RECONNECT_MAX_DELAY_MS=30000
MB_RABBITMQ_PUBLISH_RETRIES=3
MB_RABBITMQ_REUSE_CONNECTION=false
```

Valores padrão:

| Variável | Padrão | Descrição |
| --- | --- | --- |
| `MB_RABBITMQ_HOST` | `localhost` | Host do broker |
| `MB_RABBITMQ_PORT` | `5672` | Porta AMQP |
| `MB_RABBITMQ_USER` | `guest` | Usuário |
| `MB_RABBITMQ_PASSWORD` | `guest` | Senha |
| `MB_RABBITMQ_VHOST` | `/` | Virtual host |
| `MB_RABBITMQ_EXCHANGE` | `my-exchange` | Exchange principal |
| `MB_RABBITMQ_EXCHANGE_TYPE` | `topic` | Tipo do exchange principal |
| `MB_RABBITMQ_PUBLISHER_CONFIRMS` | `false` | Aguarda confirmações do broker |
| `MB_RABBITMQ_CONNECT_TIMEOUT` | `3` | Timeout de conexão em segundos |
| `MB_RABBITMQ_READ_WRITE_TIMEOUT` | `3` | Timeout de leitura/escrita em segundos |
| `MB_RABBITMQ_HEARTBEAT` | `30` | Heartbeat AMQP em segundos; `0` desativa |
| `MB_RABBITMQ_RECONNECT_ATTEMPTS` | `0` | Reconexões do **worker** após falha; `0` significa ilimitadas |
| `MB_RABBITMQ_RECONNECT_DELAY_MS` | `1000` | Backoff inicial em milissegundos |
| `MB_RABBITMQ_RECONNECT_MAX_DELAY_MS` | `30000` | Limite do backoff |
| `MB_RABBITMQ_PUBLISH_RETRIES` | `3` | Novas tentativas de `publish()` após falha de transporte; `0` desativa |
| `MB_RABBITMQ_REUSE_CONNECTION` | `false` | Reaproveita conexão e canal entre publicações do mesmo processo |

Porta inválida ou exchange vazio geram `InvalidArgumentException` ao criar a
configuração.

### RabbitMQ local com Docker

```bash
docker run --rm --name mirabel-rabbitmq \
  -p 5672:5672 -p 15672:15672 \
  rabbitmq:4-management
```

Painel de administração: `http://localhost:15672`.

Credenciais padrão: `guest` / `guest`.

## Publicando eventos

Um evento define sua routing key e recebe o payload no construtor:

```php
<?php

namespace App\Events;

use Mirabel\RabbitMQ\Event;

final class OrderReceivedEvent extends Event
{
    public static string $routingKey = 'orders.received';
}
```

Publicação:

```php
(new OrderReceivedEvent(['id' => 123]))->publish();
```

Uma routing key pode ser sobrescrita em uma publicação específica:

```php
(new OrderReceivedEvent($payload))
    ->publish(routingKey: 'orders.received.priority');
```

Para eventos críticos, forneça identidade e correlação estáveis:

```php
(new OrderReceivedEvent($payload))->publish(
    messageId: 'order-event-123',
    correlationId: 'request-456',
    idempotencyKey: 'order-123-created',
    schemaVersion: 2,
);
```

`message_id` identifica a mensagem técnica. `correlation_id` liga mensagens da
mesma operação distribuída. `x-idempotency-key` identifica a operação de negócio
que não deve ser executada duas vezes. `x-schema-version` identifica a versão do
contrato do payload.

O payload é serializado como JSON. O publisher define:

- `content_type=application/json`;
- `delivery_mode=2`, mensagem persistente;
- exchange principal configurado no ambiente;
- routing key do evento ou override da chamada.

Com confirmações habilitadas:

```env
MB_RABBITMQ_PUBLISHER_CONFIRMS=true
```

O canal executa `confirm_select()` e espera a resposta do broker por até
`MB_RABBITMQ_READ_WRITE_TIMEOUT` segundos. Se o broker responder `basic.nack`,
`publish()` lança `Mirabel\RabbitMQ\Exception\PublishNotConfirmedException`
(depois das novas tentativas). Sem confirms, `publish()` retorna assim que os
bytes saem pelo socket — a mensagem pode se perder se o broker cair em seguida.

Se a conexão ou o canal falhar durante a publicação, o publisher recria os
recursos e tenta de novo até `MB_RABBITMQ_PUBLISH_RETRIES` vezes, com o backoff
de `MB_RABBITMQ_RECONNECT_DELAY_MS`. O limite é separado do worker de propósito:
um worker pode tentar reconectar para sempre, mas uma publicação feita dentro de
uma requisição HTTP não pode segurar a resposta indefinidamente. O `message_id`
é o mesmo em todas as tentativas. Falhas de serialização do payload não são
repetidas, pois não são falhas de transporte.

Um evento sem `public static string $routingKey` e sem routing key na chamada
lança `LogicException` explicando o que falta.

## Consumindo mensagens

### API clássica recomendada

A API clássica mantém a declaração compacta originalmente usada pelo Mirabel:

```php
<?php

namespace App\Workers;

use Mirabel\RabbitMQ\Worker;

final class OrderReceivedWorker extends Worker
{
    const QUEUE = 'orders.received',
        routing_keys = [
            'orders.received'
        ],
        options = [
            'exchange_type' => 'topic'
        ],
        retry_options = [
            'x-message-ttl' => 1000,
            'max-attempts' => 4
        ],
        SCHEMA_VERSION = 1;

    public function work($msg)
    {
        try {
            process($msg->body);

            return $this->ack($msg);
        } catch (\Throwable $exception) {
            return $this->nack($msg);
        }
    }
}
```

Para iniciar o consumidor:

```php
(new OrderReceivedWorker())->subscribe();
```

`$msg` é uma instância de `PhpAmqpLib\Message\AMQPMessage`. A API clássica
mantém disponíveis:

```php
$this->ack($msg);                 // confirma e remove a mensagem
$this->nack($msg);                // falha temporária: retry, ou error na última tentativa
$this->nack($msg, requeue: true); // devolve para a mesma fila, sem atraso nem contagem
$this->reject($msg);              // falha permanente: direto para a fila error
```

Os nomes das constantes são compatíveis com a API clássica e podem ser
minúsculos (`routing_keys`, `options`, `retry_options`). O PHP diferencia os
nomes exatamente como declarados.

### API com Envelope

Para handlers tipados, o Worker também aceita `handle(Envelope $envelope)`:

```php
<?php

use Mirabel\RabbitMQ\Envelope;
use Mirabel\RabbitMQ\Worker;

final class OrderWorker extends Worker
{
    public static string $queue = 'orders.worker';
    public static array $routingKeys = ['orders.received'];
    public static array $retry = [
        'delay' => 1000,
        'max_attempts' => 4,
    ];

    public function handle(Envelope $envelope): void
    {
        process($envelope->body);
    }
}
```

O contrato do `handle()` é curto:

| O handler... | O Worker faz |
| --- | --- |
| retorna normalmente | `ack` automático (se o handler não respondeu antes) |
| lança exceção | retry; na última tentativa, fila error |
| chama `$envelope->nack()` | retry; na última tentativa, fila error |
| chama `$envelope->reject()` | fila error imediatamente, sem gastar tentativas |
| lança exceção **depois** de `ack()` | registra o erro; não responde ao broker de novo |

Uma mensagem cujo corpo não é JSON válido nunca chega ao `handle()`: ela é
enviada direto para a fila error (mensagem envenenada).

O `Envelope` fornece:

```php
$envelope->body;       // payload decodificado do JSON
$envelope->messageId;  // message_id ou string vazia
$envelope->correlationId;
$envelope->type;
$envelope->timestamp;
$envelope->idempotencyKey;
$envelope->schemaVersion;
$envelope->attempt;     // 1 na primeira entrega, 2 no primeiro retry...
$envelope->isRedelivered();
$envelope->ack();
$envelope->nack();
$envelope->reject();
```

Uma classe deve implementar `work()` ou sobrescrever `handle()`. Se nenhuma
forma for implementada, o Worker lança `LogicException` ao processar a mensagem.

### Quorum queues

Para filas replicadas num cluster, declare o tipo nas opções do worker:

```php
public static array $options = ['queue_type' => 'quorum'];
```

A fila, a `.retry` e a `.error` são criadas como quorum queues. Uma fila que
já existe com outro tipo não muda: o broker responde `PRECONDITION_FAILED`, e o
worker para com esse erro em vez de tentar reconectar.

## Retry e fila de erro

Para um worker chamado `orders.received`, a topologia criada é:

```text
orders.received
orders.received.retry
orders.received.error
```

O fluxo é:

```text
fila normal --nack--> fila retry --TTL/DLX--> exchange padrão --> fila normal
fila normal --limite atingido / reject / JSON inválido--> exchange error -> fila error
```

A fila retry recebe:

- `x-message-ttl` a partir de `$retry['delay']` (ou `retry_options['x-message-ttl']`);
- `x-dead-letter-exchange` vazio — o *default exchange*, que roteia pelo nome da fila;
- `x-dead-letter-routing-key` com o nome da fila normal.

Por isso uma mensagem em retry volta **só** para a fila do worker que falhou.
Até a versão anterior ela voltava pelo exchange principal com a routing key do
evento, e era entregue de novo a todas as outras filas ligadas àquela chave.

Quando os argumentos da fila retry mudam (outra versão da biblioteca, outro
`delay`), o RabbitMQ recusa redeclarar a fila com `PRECONDITION_FAILED`. O
Worker trata isso: se a fila retry estiver vazia, ele a apaga e recria; se ainda
tiver mensagens, ele para com uma mensagem explicando que é preciso esvaziá-la.

Uma mensagem que vai para a fila error carrega headers com o motivo:

| Header | Conteúdo |
| --- | --- |
| `x-mirabel-failure-reason` | `exception`, `nack`, `reject` ou `poison` |
| `x-mirabel-attempts` | tentativa em que falhou |
| `x-mirabel-failed-at` | timestamp Unix |
| `x-mirabel-exception` | classe e mensagem da exceção, quando houver |

A fila normal recebe:

- `x-dead-letter-exchange` apontando para o exchange retry;
- `x-dead-letter-routing-key` com o nome da fila normal.

### Contagem correta

A biblioteca não mantém um contador global no processo. Ela lê o header
`x-death` produzido pelo RabbitMQ e soma as mortes registradas na fila retry.
Essa informação sobrevive a restart do worker e funciona com múltiplas réplicas.

Quando `max-attempts` é atingido, a mensagem é publicada na exchange de erro e
confirmada no consumo original. Ela não volta para retry novamente.

## Semântica de entrega

A entrega é **at-least-once**. Isso significa que uma mensagem pode ser
processada mais de uma vez em caso de falha, timeout, reconexão ou encerramento
do processo.

Handlers devem ser idempotentes. Use `message_id` ou um identificador próprio
do payload para implementar deduplicação quando necessário.

A biblioteca não promete exactly-once delivery.

## Testes

Suíte unitária, sem broker:

```bash
composer test
```

Ela usa fábricas de conexão fake e cobre publicação, confirms, novas tentativas,
roteamento de falhas (retry, error, reject, JSON inválido) e o `ack` automático.

### Teste de integração

Os testes de integração rodam o `Worker` de verdade contra um RabbitMQ real:

```bash
docker compose up -d
composer test:integration
```

No Windows PowerShell:

```powershell
docker compose up -d
$env:MIRABEL_RABBITMQ_INTEGRATION = "1"
vendor/bin/phpunit
```

Cada teste cria exchanges e filas com nomes aleatórios e apaga tudo ao final.
Eles verificam o caminho feliz, que o retry não vaza para outras filas, a fila
error com os headers de falha, a mensagem envenenada e a migração da fila retry.
O CI (`.github/workflows/ci.yml`) roda as duas suítes em PHP 8.2, 8.3 e 8.4.

## Uso com Laravel

O Core não registra Service Provider Laravel. Em uma aplicação Laravel, basta
instalar o pacote local ou publicado e manter as variáveis `MB_RABBITMQ_*` no
`.env`.

As classes de evento e worker não precisam usar `Illuminate\*`:

```php
(new App\Events\OrderReceivedEvent(['id' => 123]))->publish();
```

Para processos de longa duração, prefira um Artisan Command dedicado ou um
processo supervisionado em vez de executar `subscribe()` manualmente em um
Tinker de produção.

O Worker tenta reconectar após falhas de conexão usando backoff exponencial. O
contador de tentativas volta a zero depois de uma conexão bem-sucedida, então
`MB_RABBITMQ_RECONNECT_ATTEMPTS` limita quedas seguidas, não quedas ao longo da
vida do processo.

`$worker->stop()` pede o encerramento: o Worker termina a mensagem em mãos e
sai de `subscribe()` em até um segundo. Quando `pcntl` está disponível,
`SIGTERM` e `SIGINT` chamam `stop()`. `$worker->health()` informa se o
consumidor está de fato conectado.

O projeto de exemplo em
`C:\projetos\IA\laravel-microservices-mirabel-rabbitmq` usa um Composer `path`
com symlink para o checkout local da biblioteca.

## Outbox transacional

Para eventos críticos, não publique diretamente dentro da operação que altera
o banco. Gere um `OutboxMessage` e grave-o na mesma transação da mudança de
domínio:

```php
$event = new OrderReceivedEvent(['id' => 123]);
$message = $event->toOutboxMessage(
    messageId: 'order-event-123',
    correlationId: 'request-456',
    idempotencyKey: 'order-123-created',
);

$database->transaction(function () use ($order, $message): void {
    $orderRepository->save($order);
    $outboxStore->add($message);
});
```

Depois, um processo separado despacha os registros pendentes:

```php
$publisher = new AmqpOutboxPublisher(
    ConnectionConfig::fromEnvironment(),
);
$dispatcher = new OutboxDispatcher($outboxStore, $publisher, $logger);

$dispatcher->dispatch(limit: 100);
```

O store deve implementar `OutboxStoreInterface` sobre a tecnologia da aplicação
(banco relacional, Redis Streams ou outra). A operação `add()` precisa participar
da mesma transação que salva o dado de negócio. O dispatcher marca publicado
somente depois do sucesso do publisher; em falha, chama `markFailed()` e deixa o
registro disponível para retry conforme a política do store.

Esse padrão resolve o problema de uma transação do banco confirmar enquanto a
publicação RabbitMQ falha. Ele não promete exactly-once: o dispatcher pode
publicar e cair antes de marcar o registro como publicado, portanto o consumidor
deve usar a idempotência documentada acima.

## Extensão e testes unitários

A criação de conexão é substituível sem alterar `publish()` ou `subscribe()`:

```php
use Mirabel\RabbitMQ\Connection\ConnectionFactoryInterface;
use Mirabel\RabbitMQ\ConnectionConfig;
use PhpAmqpLib\Connection\AbstractConnection;

final class FakeConnectionFactory implements ConnectionFactoryInterface
{
    public function __construct(private AbstractConnection $connection) {}

    public function connect(ConnectionConfig $config): AbstractConnection
    {
        return $this->connection;
    }
}
```

`Event` e `Worker` expõem `connectionFactory()` como método protegido. Uma
subclasse de teste pode sobrescrevê-lo e devolver uma fábrica fake. Isso permite
testar publicação e consumo sem abrir socket.

### Deduplicação

O Worker aceita um store de idempotência opcional. A biblioteca consulta a chave
antes do handler e confirma uma mensagem duplicada sem executá-la novamente. A
chave é `x-idempotency-key` ou, como fallback, `message_id`.

```php
use Mirabel\RabbitMQ\Idempotency\IdempotencyStoreInterface;
use Mirabel\RabbitMQ\Worker;

final class OrderWorker extends Worker
{
    public function __construct(private IdempotencyStoreInterface $store) {}

    protected function idempotencyStore(): ?IdempotencyStoreInterface
    {
        return $this->store;
    }
}
```

O store deve ser atômico e persistente, por exemplo Redis com `SET NX` ou uma
tabela com índice único. Um array em memória não protege contra múltiplos
workers nem contra restart do processo.

## Observabilidade

O Core depende de `psr/log` e usa `NullLogger` por padrão. Portanto, nenhuma
configuração de logger é obrigatória e a biblioteca não escreve diretamente no
stdout.

Uma integração pode fornecer seu logger sobrescrevendo `logger()` na classe do
evento ou worker:

```php
use Psr\Log\LoggerInterface;
use Mirabel\RabbitMQ\Worker;

final class OrderWorker extends Worker
{
    public function __construct(private LoggerInterface $appLogger) {}

    protected function logger(): LoggerInterface
    {
        return $this->appLogger;
    }
}
```

Eventos registrados:

- publicação concluída;
- publicação que será repetida;
- publicação definitivamente falha.

Workers registrados:

- reconexão após falha;
- mensagem encaminhada para retry;
- mensagem enviada para error queue;
- encerramento gracioso.

O campo `exception` é enviado no contexto quando houver uma exceção. O logger
da aplicação decide como formatar, armazenar ou encaminhar esses dados.

## Limitações atuais

Ainda não fazem parte do Core atual:

- múltiplas conexões nomeadas;
- TLS configurável;
- schema validation;
- priority queues e single active consumer como opções de alto nível;
- mais de um host na configuração (para um cluster, use um balanceador ou um
  nome de DNS na frente dos nós);
- pacote Laravel separado;
- `mandatory` + return listener (hoje, uma mensagem sem nenhuma fila ligada à
  routing key é descartada pelo broker em silêncio, mesmo com confirms).

O serializer pode ser trocado sobrescrevendo `serializer()` no evento ou worker.
Publicações de alto volume num mesmo processo podem reaproveitar a conexão com
`MB_RABBITMQ_REUSE_CONNECTION=true`.

Esses itens devem ser adicionados preservando a API clássica e mantendo a
camada Core independente de framework.
