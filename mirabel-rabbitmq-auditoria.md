# Auditoria Técnica e Proposta de Evolução — Mirabel RabbitMQ

> Baseado no código real em `C:\projetos\IA\mirabel-rabbitmq` (branch `master`, tags até `v1.0.5`).
> Pesquisa de mercado feita em setembro/2026 (php-amqplib, ext-amqp, Enqueue, PHP support windows).

> **Atualização pós-auditoria — DX travada (ver Seção 5).** Depois deste relatório, a forma de uso da API pública foi discutida à parte, tomando como referência o [artigo original do autor](https://medium.com/@pablicio/micro-servi%C3%A7os-utilizando-laravel-e-rabbitmq-de-uma-maneira-realmente-simplificada-n%C3%A3o-t%C3%B4-c7f4f4a4422) sobre o uso do Mirabel v1. Decisão: **preservar a elegância exata do artigo** — `(new App\Events\X('payload'))->publish()` e `(new App\Workers\Y)->subscribe()`, classes curtas com identidade declarada em propriedades estáticas no topo, zero bootstrap manual — trocando só o motor por trás (sem `config()` do Laravel, sem `self::CONST`/`defined()`, retry corrigido via `x-death`). Isso **substitui** a API descrita originalmente na Seção 5 e ajusta as Seções 4, 16 e 19. O texto original de auditoria/pesquisa dessas seções permanece válido; só os exemplos de API foram atualizados.

---

## 1. Executive Summary

O Mirabel RabbitMQ, hoje, é um **conjunto de 7 traits PHP** (~15 KB de código) que envolvem o `php-amqplib` para uso dentro do Laravel. Não é uma biblioteca — é um utilitário de projeto específico que foi empacotado. Isso não é um julgamento pejorativo: para o escopo em que nasceu (facilitar pub/sub dentro de uma app Laravel), ele resolve. O problema é a lacuna entre o que existe e o que o README já promete ("Become agnostic to other frameworks", "Add unit tests" — itens do próprio TODO do projeto).

**Veredito direto à pergunta central:** vale a pena ressuscitar o Mirabel, mas não como "refatoração" do código atual. O código atual (traits com `config()` global embutido, `self::CONST` para configuração, sem interfaces, sem testes reais) não é uma base sólida para evoluir incrementalmente — é uma base para **reescrever do zero, mantendo a filosofia de simplicidade de API e o conhecimento de domínio (retry, DLQ, exchanges) já validado no README**. A oportunidade real de mercado existe: não há hoje uma biblioteca PHP de RabbitMQ que seja simultaneamente (a) framework-agnostic desde o Core, (b) com DX tão simples quanto a do Mirabel para o caso comum, e (c) ativamente mantida. `php-amqplib` é um protocolo de baixo nível (correto, mas verboso). `enqueue/amqp-lib` está tecnicamente vivo mas com saúde de manutenção baixa (1 mantenedor ativo, pouca atividade de comunidade). Symfony Messenger resolve bem para quem já está no ecossistema Symfony, mas é pesado para PHP puro ou para quem não usa Messenger. Esse é o espaço.

---

## 2. Auditoria do código atual

### 2.1 Estrutura real encontrada

```
mirabel-rabbitmq/
├── composer.json
├── composer.lock
├── phpunit.xml
├── README.md
├── config/
│   └── mirabel_rabbitmq.php
├── src/
│   ├── MirabelRabbitmqServiceProvider.php   (16 linhas)
│   ├── RabbitMQHelpers.php                  (26 linhas)
│   ├── RabbitMQEventsConnection.php         (43 linhas)
│   ├── RabbitMQWorkersConnection.php        (137 linhas)
│   ├── RabbitMQWorkersError.php             (35 linhas)
│   ├── RabbitMQWorkersNormal.php            (35 linhas)
│   └── RabbitMQWorkersRetry.php             (43 linhas)
└── tests/
    └── RabbitMQHelpersTest.php
```

Não existe `Contracts/`, `Messaging/`, `Connection/`, `Publisher/`, `Consumer/` nem qualquer separação em camadas — é um pacote flat de traits no namespace único `Pablicio\MirabelRabbitmq`. Não há branch de trabalho ativa localmente: o clone é raso (`master` apenas); existe uma ref remota `refactoring-v2` que não foi baixada para este working tree, então não pôde ser auditada — vale conferir seu conteúdo manualmente antes de começar, pode haver decisões já tomadas ali.

### 2.2 O que o código realmente faz

- **`RabbitMQEventsConnection`** (trait, usado via `use` em classes de evento): abre uma `AMQPStreamConnection` nova, declara o exchange, publica **uma** mensagem, fecha canal e conexão. Uma conexão TCP inteira é aberta e fechada a cada `publish()`.
- **`RabbitMQWorkersConnection`** (trait "orquestrador"): concentra toda a lógica de um worker — declara exchange geral, delega para `normalSettings`/`retrySettings`/`errorSettings`, faz bind das routing keys, monta o callback de consumo, configura QoS e entra no loop `while (count($channel->callbacks)) { $channel->wait(); }`.
- **`RabbitMQWorkersNormal` / `RabbitMQWorkersRetry` / `RabbitMQWorkersError`**: cada um declara seu próprio exchange/queue com DLX apontando para o próximo estágio (fila normal → fila retry → fila error), implementando na prática o padrão "parking lot queue" do RabbitMQ via TTL + DLX.
- **`RabbitMQHelpers`**: `ack`/`nack`/`reject` (wrappers de uma linha sobre `AMQPMessage`) e `hasCustomConfig` (equivalente a `$array[$key] ?? $default`).
- **`MirabelRabbitmqServiceProvider`**: só publica e faz merge do arquivo de config Laravel. Não registra nada no container (`Bus`, `Connection` etc. não existem como classes — tudo é trait aplicado diretamente na classe do usuário).

### 2.3 Classificação de problemas

**CRÍTICO**

1. **Acoplamento total a Laravel no Core.** `config()` (helper global do Laravel) é chamado diretamente dentro de `RabbitMQEventsConnection`, `RabbitMQWorkersConnection`, `RabbitMQWorkersNormal`, `RabbitMQWorkersRetry` e `RabbitMQWorkersError` — cinco dos sete arquivos de `src/`. Sem um container Laravel rodando, **nada funciona**, nem em PHP puro nem em Symfony. Isso invalida diretamente o objetivo central deste projeto de evolução.
2. **Nenhuma abstração de conexão.** Uma `AMQPStreamConnection` nova é criada a cada `publish()` e a cada `subscribe()`. Não há pool, não há reaproveitamento de canal, não há reconexão em caso de falha de rede — se a conexão cair no meio do `while ($channel->wait())`, o worker morre sem tratamento algum.
3. **Configuração via constantes de classe (`self::QUEUE`, `self::options`, `self::retry_options`) checadas com `defined('self::options')`.** Isso é um anti-padrão em PHP: `defined('self::X')` testa se a constante existe *na classe que define o trait*, não necessariamente resolve como o programador espera em hierarquias de herança, é impossível de tipar, impossível de validar em IDE, e propenso a erro de digitação silencioso (uma constante mal escrita não gera erro, apenas o `defined()` retorna `false` e o comportamento vira o default).
4. **Retry/DLQ com lógica de contagem quebrada.** Em `RabbitMQWorkersConnection::subscribe()`, `$max_retry_counter` é uma variável **local ao processo do worker**, incrementada a cada iteração do loop `while`, não por mensagem. Isso não é um contador de tentativas por mensagem — é um contador de "quantas vezes o worker já rodou wait() desde que começou". Duas mensagens diferentes que chegam em sequência compartilham (incorretamente) o mesmo contador. O RabbitMQ já resolve contagem de tentativas nativamente via `x-death` header quando se usa DLX, e este código não lê esse header — ele reinventa (mal) o que o protocolo já oferece.
5. **Sem testes reais.** O único teste existente (`RabbitMQHelpersTest`) cria um `Stub` e configura o próprio stub para retornar o valor que o teste espera (`$stub->method('ack')->willReturn('ack')`), depois afirma que o stub retornou o que foi configurado para retornar. Isso não testa a implementação real de `RabbitMQHelpers::ack()` — testa a capacidade do PHPUnit de criar stubs. Cobertura funcional real: 0%.
6. **`unserialize()` implícito ausente, mas também não há serialização estruturada.** O payload é passado como string crua (`new AMQPMessage($this->payload)`). Não há content-type, não há versionamento de schema, não há metadata (correlation ID, timestamps, message ID). Isso funciona apenas se o publisher serializa manualmente antes de passar a string.

**ALTO**

7. **Sem graceful shutdown.** Não há tratamento de `SIGTERM`/`SIGINT`. Em Docker/Kubernetes, um `docker stop` ou um rolling update mata o processo no meio do processamento de uma mensagem, sem chance de `nack` ou de finalizar limpo — mensagens podem ficar "unacked" até o timeout de conexão.
8. **Publisher sem confirms.** `basic_publish` é chamado sem `confirm_select()`/`wait_for_pending_acks()`. Não há garantia de que a mensagem realmente chegou ao broker antes do `channel->close()`.
9. **Um único exchange geral compartilhado com múltiplos workers.** No `subscribe()`, o worker declara **dois exchanges** (um "seu" com nome igual ao da fila, e o "geral" vindo do config) e faz bind da fila em ambos com a mesma routing key. Isso é uma decisão arquitetural implícita e não documentada — não fica claro para quem lê o código por que dois exchanges existem, nem o critério de quando usar um ou outro.
10. **Erro de digitação/bug latente:** em `RabbitMQWorkersNormal::normalSettings()`, a linha `$queue_options = count(self::options) ? self::options : [];` assume que `self::options` sempre está definida (chama `count()` direto, sem `defined()` antes) — diferente do padrão usado no resto do arquivo. Se uma classe de worker não declarar `const options`, isso gera erro fatal de PHP (constante indefinida), não um fallback gracioso.
11. **Sem reconexão/circuit breaker.** Se o RabbitMQ cair, a aplicação simplesmente lança a exceção do `php-amqplib` sem qualquer estratégia de retry na camada de conexão.
12. **Dependência de mutabilidade de propriedades privadas via trait.** `RabbitMQEventsConnection` define `private $routingKey` e `private $payload`, esperando que a classe que usa o trait as popule no construtor — funciona, mas é frágil: nada impede a classe consumidora de esquecer de setar `$this->routingKey`, e o erro só aparece em runtime ao publicar `null`.

**MÉDIO**

13. **`README.md` documenta um comportamento de `options` que diverge do código.** O README diz "The options array is required to declare. case [], we will assume the settings of .env" — mas o código (`normalSettings`) usa `count(self::options)`, que quebra com array vazio de forma diferente do que "assumir .env" sugere; na prática, array vazio funciona (cai nos defaults do `hasCustomConfig`), mas a documentação descreve isso de forma imprecisa.
14. **Sem suporte a Quorum Queues, Streams, Priority Queues, Single Active Consumer** — nenhum recurso moderno do RabbitMQ (pós-3.8) é exposto.
15. **Exchange type fixo lido do config global em vários pontos**, em vez de ser parametrizável por publisher/consumer de forma consistente (às vezes é, às vezes cai no config geral — comportamento inconsistente entre `normalSettings`, `retrySettings` e `errorSettings`).
16. **Sem logging.** Nenhum `Psr\Log\LoggerInterface` ou equivalente — falhas de conexão, mensagens rejeitadas, etc. não deixam rastro algum.
17. **Sem suporte a múltiplas conexões nomeadas**, apesar da config já usar a chave `rabbitmq-php` como se fosse um array de conexões nomeadas — o código nunca lê o nome, sempre acessa `connections.rabbitmq-php` hardcoded.

**BAIXO**

18. `phpunit.xml` referencia `"Doblhofer/HelloWorld Test Suite"` como nome da suíte — resíduo de boilerplate/template não renomeado.
19. Comentários em blocos de `#` como separador visual (`########...`) em vez de organização por método/classe — sintoma da falta de separação em camadas, não um problema em si.
20. README com erro de digitação ("Disclaimerm") e trecho final já reconhecendo, na voz do próprio autor, que o projeto "does not work correctly in many scenarios" e é "for testing and learning" — uma honestidade que deve ser preservada no espírito da nova versão (comunicar claramente o que é experimental).
21. `composer.json` não declara `require: php` (nenhuma versão mínima de PHP explícita) nem `minimum-stability`.

### 2.4 O que o código faz bem (não descartar)

Apesar dos problemas, três decisões de produto do Mirabel original são boas e devem ser preservadas na nova versão:

- **O padrão de retry/DLQ em cascata (normal → retry com TTL → error)** é o padrão correto e amplamente usado no ecossistema RabbitMQ (não existe "delayed retry" nativo no protocolo AMQP 0-9-1 sem plugin; TTL + DLX é a forma canônica de simular isso). A *implementação* do contador está quebrada (item crítico 4), mas a *arquitetura* de filas está certa.
- **A ideia de uma API mínima (`publish()`, `work($msg)`, `ack/nack/reject`)** é o ponto de partida certo para a experiência de desenvolvedor. O objetivo não é jogar isso fora, é dar a essa API uma implementação correta por trás.
- **Exposição extensa de parâmetros nativos do AMQP** (todos os argumentos de `exchange_declare`/`queue_declare`) mostra que o autor não tentou esconder o protocolo — só não organizou bem essa exposição. Isso é coerente com a diretriz deste projeto de "não esconder completamente conceitos importantes do RabbitMQ".

---

## 3. Gap Analysis

| Necessidade | Estado atual | Gap |
|---|---|---|
| Rodar sem Laravel | Impossível (`config()` hardcoded) | Total — é o gap mais crítico |
| Reconexão automática | Inexistente | Total |
| Retry com contagem correta | Quebrado (bug de contador) | Total |
| Testes reais | 0% de cobertura funcional | Total |
| Graceful shutdown | Inexistente | Total |
| Publisher confirms | Inexistente | Total |
| Logging/observabilidade | Inexistente | Total |
| Serialização estruturada | Payload cru, sem metadata | Alto |
| Múltiplas conexões nomeadas | Config existe, código ignora | Médio (fácil de fechar) |
| API simples para caso comum | Existe e é boa | Nenhum — preservar |
| Cobertura de opções nativas AMQP | Extensa | Nenhum — preservar e organizar |

---

## 4. Arquitetura proposta

A estrutura sugerida no prompt original é um bom ponto de partida, mas pode ser simplificada — separar `Publisher/` e `Consumer/` de `Messaging/` cria fronteiras artificiais quando o volume de código por pasta é pequeno no início. Proposta ajustada:

```
mirabel-rabbitmq/                    (mirabel/rabbitmq — o Core)
│
├── src/
│   ├── Contracts/              # Interfaces: Serializer, AmqpDriverInterface, RetryPolicy...
│   ├── Connection/             # ConnectionManager (reconexão), leitura lazy de env (MB_RABBITMQ_*)
│   ├── Messaging/              # Envelope, Headers, Metadata
│   ├── Event.php               # Classe base pública: identidade estática ($routingKey) + publish()
│   ├── Worker.php               # Classe base pública: identidade estática ($queue/$routingKeys/$retry) + subscribe()
│   ├── Retry/                  # RetryPolicy, estratégias (fixed, exponential), leitura de x-death
│   ├── DeadLetter/             # Configuração de filas DLQ/retry em cascata
│   ├── Serialization/          # JsonSerializer (default), SerializerInterface
│   ├── Topology/                # Exchange/Queue declaration (fusão do que seria Exchange/ + Queue/ separados)
│   ├── Middleware/             # Pipeline opcional (logging, tracing, etc.)
│   ├── Observability/          # PSR-3 adapters, contadores simples
│   └── Exceptions/
│
├── integrations/
│   ├── laravel/                # Composer package separado: mirabel/rabbitmq-laravel
│   └── symfony/                # Composer package separado: mirabel/rabbitmq-symfony (fase futura)
│
└── tests/
    ├── Unit/
    └── Integration/            # Contra RabbitMQ real via Testcontainers/Docker
```

> `Event` e `Worker` (classes base públicas, direto em `src/`, não em subpasta) são o que a aplicação efetivamente estende/usa no dia a dia — ver Seção 5. `Bus`/`Consumer` como classes genéricas separadas, cogitadas numa versão anterior desta proposta, foram descartadas: a identidade (fila, routing keys, retry) fica na própria classe de evento/worker via propriedades estáticas, não em um objeto de bus/consumer passado à parte.

Diferenças da proposta original do prompt:
- **`Exchange/` e `Queue/` viram `Topology/`.** Em RabbitMQ, exchange, queue e bindings são sempre declarados e pensados juntos (é literalmente isso que a topologia de uma fila com DLQ exige). Separar as duas pastas obrigaria arquivos anêmicos referenciando um ao outro o tempo todo.
- **`adapters/` (do prompt original, fora de `src/`) vira `Connection/` dentro de `src/`, com uma interface `AmqpDriverInterface`.** Não crie uma pasta de nível de projeto separada para isso — é parte do Core, só que isolada atrás de uma interface (ver seção 9).
- **`integrations/` fica fora do pacote Core** (repositórios/pacotes Composer separados: `mirabel/rabbitmq`, `mirabel/rabbitmq-laravel`, `mirabel/rabbitmq-symfony` — nomenclatura final na Seção 24), não como subpasta do mesmo pacote — ver seção 6/24 para o porquê.

O princípio de dependência é estritamente:

```
Application → mirabel/laravel (opcional) → mirabel/rabbitmq (Core) → php-amqplib
Application → mirabel/rabbitmq (Core) → php-amqplib          [PHP puro]
Application → Symfony Messenger transport próprio → mirabel/rabbitmq → php-amqplib   [Symfony]
```

`mirabel/rabbitmq` **nunca** importa `Illuminate\*` nem `Symfony\*` em `composer.json` (`require`), nem em código.

---

## 5. API proposta — versão final travada (PHP puro como primeira classe)

> Decisão tomada após a auditoria, revisando o [artigo original do autor sobre o Mirabel v1](https://medium.com/@pablicio/micro-servi%C3%A7os-utilizando-laravel-e-rabbitmq-de-uma-maneira-realmente-simplificada-n%C3%A3o-t%C3%B4-c7f4f4a4422). O objetivo explícito foi **preservar a elegância exata daquele artigo** — uma classe = um evento/worker, quase toda ela configuração declarativa, um verbo só pra usar (`publish()`/`subscribe()`) — e trocar somente o motor por trás. Duas propostas intermediárias foram descartadas no caminho: (1) `Bus::create($config)`/`Consumer::create($config)` com closures (explorável, mas verboso e distante do artigo) e (2) uma versão com classes base mas ainda exigindo `Mirabel::configure([...])` num bootstrap manual. A versão final elimina os dois.

### Evento (Publisher)

```php
<?php

namespace App\Events;

use Mirabel\RabbitMQ\Event;

class OrderReceivedEvent extends Event
{
    public static string $routingKey = 'order-services.order.received';

    public function __construct(public mixed $payload) {}
}
```

```php
(new App\Events\OrderReceivedEvent('Received'))->publish();
```

`$routingKey` é `public static string`, não `const` (permite override por herança de forma mais natural que `const`) e não `protected` por instância (é dado de classe — toda instância de `OrderReceivedEvent` tem a mesma rota, então não deveria viver no `$this`). Substitui diretamente `const ROUTING_KEY` + atribuição manual no construtor (`$this->routingKey = self::ROUTING_KEY`) do Mirabel v1 — mesma economia de linhas, com tipo checado pelo PHP em vez de `defined('self::X')`.

`Event::publish()` aceita sobrescrever a rota no momento da chamada quando necessário (reprocessamento, republicação em outra fila), sem exigir isso no caminho comum:

```php
(new OrderReceivedEvent($payload))->publish(); // usa $routingKey estático da classe
(new OrderReceivedEvent($payload))->publish(routingKey: 'order.created.priority'); // override pontual
```

### Worker (Consumer)

```php
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

```php
(new App\Workers\TestOrderDoneWorker)->subscribe();
```

Três propriedades estáticas de identidade no topo (fila, routing keys, retry), coladas — lê como um cabeçalho de configuração, equivalente ao bloco `const QUEUE = ..., routing_keys = [...], retry_options = [...]` do artigo original, só que cada propriedade tipada (fim do bug crítico #3/#4 da auditoria: `defined('self::options')` e o contador de retry quebrado). `handle(Envelope $envelope)` troca `work($msg)`. Ack/nack/reject continuam **explícitos**, mas pendurados no `Envelope` como método de uma linha (`$envelope->ack()`), não vindos de um trait à parte que precisa de `use` (`RabbitMQHelpers` no código atual). Se o handler lança exceção sem chamar `nack()`, o Mirabel intercepta e trata como falha (entra no fluxo de retry via `x-death`, Seção 12) — o ack explícito é o caminho recomendado e documentado, não o único jeito de o sistema saber o que aconteceu.

### Configuração — sem bootstrap manual, sem `config()` do Laravel

Em vez de `config()` do Laravel espalhado pelo Core (problema crítico #1) ou de exigir um passo explícito tipo `Mirabel::configure([...])`, a lib **lê as variáveis `MB_RABBITMQ_*` do ambiente automaticamente e de forma lazy**, na primeira vez que qualquer `Event`/`Worker` é usado — via `getenv()` puro do PHP, sem depender do helper `env()` do Laravel:

```env
MB_RABBITMQ_HOST=localhost
MB_RABBITMQ_PORT=5672
MB_RABBITMQ_USER=guest
MB_RABBITMQ_PASSWORD=guest
MB_RABBITMQ_EXCHANGE=my-exchange
```

```php
(new App\Events\OrderReceivedEvent('Received'))->publish();
```

Nada mais — mesma experiência do artigo original (`.env` configurado, classe criada, chamada feita). Quem precisar sobrescrever host/porta programaticamente (testes, múltiplos brokers) continua podendo, mas isso deixa de ser passo obrigatório do caminho feliz.

### Múltiplos eventos/workers — sem mudança de rotina

```php
# Workers
(new App\Workers\StoreOrderReceivedWorker)->subscribe();
(new App\Workers\TestOrderDoneWorker)->subscribe();

# Events
(new App\Events\StoreOrderCreatedEvent('Created'))->publish();
(new App\Events\OrderReceivedEvent('Received'))->publish();
```

Identico, char por char, ao uso demonstrado no artigo original com o Mirabel v1 — a migração de v1 para v2 para quem já usa a lib é trocar `use RabbitMQEventsConnection` por `extends Event` e `const` por `public static`, nada além disso no código de negócio.

### O que fica fora da Seção 19 original

A Seção 19 abaixo propõe parâmetros nomeados como equilíbrio simplicidade/controle — isso permanece válido para o caso avançado de `publish()` (override de routing key, opções de publish), mas **não para a identidade de fila/routing keys/retry do Worker**, que fica em propriedades estáticas, não em argumentos de método — exatamente para manter a classe legível como um cabeçalho declarativo, como no artigo original.

---

## 6. Laravel como integração opcional

Estratégia avaliada: **pacotes separados**, não uma pasta dentro do mesmo repositório Composer (`mirabel/rabbitmq` vs. `mirabel/rabbitmq-laravel`, ver decisão final na seção 24). Isso é decisivo porque `composer.json` do Core precisa ficar **sem nenhuma menção a `illuminate/*`**, nem em `require-dev` — se ficar no mesmo repositório mas em pasta separada, o risco de vazamento de dependência em algum autoload ou teste é maior e a intenção fica menos clara para quem audita o `composer.json`.

`mirabel/laravel` fornece:
- `ServiceProvider` que registra `Bus`/`Consumer` no container a partir da config Laravel (traduzindo `config/mirabel.php` para um `ConnectionConfig` do Core).
- Facade opcional (`Mirabel::publish(...)`) — opcional mesmo, o Core já é ergonômico sem ela.
- Artisan command: `php artisan mirabel:consume {queue}` chamando o `Consumer` do Core.
- Binding de um adapter `Psr\Log\LoggerInterface` → `Illuminate\Log` automaticamente.
- Publica config Laravel-style (`env()`), mas o Core nunca vê `env()` — o Service Provider traduz para `ConnectionConfig` explícito.

Nada disso é requisito para instalar `mirabel/rabbitmq`. Isso resolve diretamente o item do TODO do próprio README ("Become agnostic to other frameworks").

---

## 7. Symfony (avaliação, não implementação nesta fase)

Duas estratégias possíveis, e a diferença importa:

1. **`mirabel/symfony` como bundle simples**, expondo `Bus`/`Consumer` do Core como serviços no container Symfony, com um `Command` (`bin/console mirabel:consume`) — análogo à integração Laravel.
2. **Transport customizado para Symfony Messenger** (`Symfony\Component\Messenger\Transport\TransportInterface`), permitindo que quem já usa Messenger use `mirabel/rabbitmq` como implementação de transporte por trás, convivendo com o `symfony/amqp-messenger` nativo.

A opção 2 é estrategicamente mais interessante — o ecossistema Symfony já espera trabalhar via Messenger, não via um `Bus` paralelo — mas também é mais trabalho e só faz sentido se houver um diferencial real sobre o transport AMQP nativo do Messenger (que já existe e é maduro). Recomendação: começar pela opção 1 (mais simples, entrega valor a quem quer usar Mirabel em Symfony sem estar comprometido com Messenger) e avaliar a opção 2 como fase posterior, só se houver demanda real. O Core nunca depende de nenhuma das duas.

---

## 8. RabbitMQ moderno — o que entra no Core vs. o que é avançado

| Recurso | Core (MVP+) | Avançado (fase posterior) |
|---|---|---|
| Direct / Topic / Fanout exchanges | ✅ Core | — |
| Headers exchange | — | Avançado (uso raro, API própria) |
| Durable queues/exchanges | ✅ Core (default) | — |
| Publisher Confirms | ✅ Core (opt-in simples) | — |
| Consumer Acks (manual) | ✅ Core | — |
| Prefetch/QoS | ✅ Core | — |
| TTL + DLX (retry/DLQ) | ✅ Core | — |
| Connection/channel recovery | ✅ Core (é pré-requisito de produção) | — |
| Quorum Queues | — | Avançado (flag na declaração de topologia) |
| Priority Queues | — | Avançado |
| Single Active Consumer | — | Avançado |
| RabbitMQ Streams | — | Fora do escopo do Core; API de Streams é fundamentalmente diferente (não é AMQP 0-9-1 clássico) — se necessário, tratar como um driver/adapter separado no futuro, nunca misturado à API de filas clássicas |

Justificativa: Quorum Queues e Priority Queues são configuração de **argumentos de declaração de fila**, não mudam a API pública — dá para suportá-los desde o Core como opções da `Topology`, sem esforço de design adicional. O motivo de listá-los como "avançado" é de **documentação e default**, não de código: o Mirabel não deve decidir por padrão que toda fila é quorum, porque isso muda comportamento operacional (quorum queues não suportam todos os argumentos de fila clássica, por exemplo prioridade e classic-queue-specific TTL behavior) — deixe explícito e opt-in.

---

## 9. php-amqplib vs. ext-amqp

Pesquisa feita em setembro/2026:

- **`php-amqplib/php-amqplib`** está na versão **3.7.4** (lançada em nov/2025), é mantida ativamente por engenheiros da equipe RabbitMQ/VMware/Broadcom, e já testa contra **PHP 8.5** em CI. É pura PHP (zero dependência de extensão C), o que a torna trivialmente portável entre Linux/Windows/macOS e imagens Docker sem etapa de compilação.
- **`ext-amqp`** (extensão C, pacote `php-amqp/php-amqp`) segue viva, mas depende de compilar contra `librabbitmq`, e builds de pacotes de sistema para ela falham com frequência em ambientes reais (observado em builds recentes do MacPorts, múltiplas falhas de "install-dependencies" em versões de PHP diferentes). Em Windows, historicamente é a opção mais dolorosa de instalar. Em containers Docker minimalistas (`alpine`, `distroless`), exige etapa extra de build da extensão.
- **`enqueue/amqp-lib`**, que already usa `php-amqplib` por baixo como uma das opções de transporte do Enqueue, está classificada como "safe to use" mas com **saúde de manutenção de 54/100** — um único mantenedor ativo nos últimos 3 meses, baixa atividade de comunidade. Não é um sinal de alarme de segurança, mas é um sinal de que apostar todo o design em torno do ecossistema Enqueue não é uma aposta forte hoje.

**Recomendação:** o Core usa **exclusivamente `php-amqplib`** atrás de uma interface fina (`Contracts\AmqpDriverInterface` ou similar) — não porque haverá múltiplos drivers no MVP, mas porque isolar a única dependência de infraestrutura do Core atrás de uma interface é o que permite testar `Bus`/`Consumer` com um driver fake em testes unitários, sem subir RabbitMQ real. Suportar `ext-amqp` como driver alternativo **não deve entrar no roadmap inicial** — é complexidade real (duas implementações completas para manter, testar e documentar) sem demanda comprovada; `php-amqplib` sozinho já resolve portabilidade (que era a principal razão para considerar `ext-amqp` no passado, quando a implementação pura-PHP era mais lenta). Reavaliar apenas se houver relato concreto de gargalo de performance que só `ext-amqp` resolva — o que hoje não é o caso: `php-amqplib` é usado em produção por bibliotecas ativamente mantidas para casos de consumo assíncrono de alto volume.

Isso responde diretamente à seção 9 do prompt original: a abstração de driver vale a complexidade **pela testabilidade**, não pela portabilidade entre múltiplos backends reais — que é uma justificativa diferente e mais concreta do que "elegância".

---

## 10. Modelo de mensagens

Conceitos que devem entrar no Core, com justificativa:

- **`Message`**: interface simples — o payload de domínio (ex.: `OrderCreated`). Não precisa de classe base obrigatória; pode ser qualquer objeto, desde que o `Serializer` saiba lidar com ele (ver seção 11).
- **`Envelope`**: o que o consumidor recebe — o `Message` desserializado + metadata (headers, routing key original, delivery tag). É o `Envelope`, não o `Message`, que carrega `ack()`/`nack()`/`reject()` — separar payload de domínio de mecanismo de confirmação é o que permite ao usuário testar seus handlers sem mockar AMQP.
- **Metadata mínima obrigatória por mensagem publicada**: `messageId` (UUID gerado automaticamente se não fornecido), `timestamp`, `contentType`.
- **Correlation ID / Causation ID**: suportados como headers opcionais no `Envelope`, não como conceito de primeira classe forçado — nem toda aplicação precisa de rastreamento de causalidade, mas quem precisa deve conseguir setar e ler sem trabalho extra.
- **Event vs. Command vs. Request/Response**: **não modelar como tipos distintos no Core.** Isso é uma decisão de modelagem de domínio da aplicação, não do transporte. RabbitMQ não distingue "evento" de "comando" no protocolo — a distinção é semântica, imposta pela aplicação (ex.: convenção de nomes de routing key, ou classes marcadoras na camada de domínio do usuário). Forçar essa distinção no Core seria abstração artificial (violaria diretamente a seção 26 do prompt original).
- **Schema Version**: suportado como header opcional (`schema-version` ou similar), mas a validação/migração de schema **fica fora do Core** — é responsabilidade do `Serializer` customizado da aplicação, se ela precisar disso.

---

## 11. Serialização

- **Default: JSON.** Simples, interoperável entre linguagens (importante em microsserviços poliglotas), legível para debug.
- **`unserialize()` do PHP nunca deve ser opção padrão ou documentada como recomendada** — é um vetor de RCE conhecido quando o payload vem de fora do processo (e mensagens de fila, por definição, vêm de fora). Se alguém quiser usar serialização nativa do PHP por conta própria via `Serializer` customizado, isso é escolha explícita da aplicação, não default da biblioteca.
- **`SerializerInterface` no Core**, com um `JsonSerializer` como implementação padrão. Permite ao usuário plugar Symfony Serializer, `league/json-guard`, ou qualquer outro, sem o Core depender de nenhum deles.
- **Versionamento de schema / backward compatibility**: o Core expõe o header `schema-version` como convenção, mas a lógica de "se versão 1, faça X; se versão 2, faça Y" é responsabilidade do handler da aplicação. Documentar o padrão recomendado (upcasting no handler) é suficiente — não implementar um sistema de migração de schema embutido.

---

## 12. Retry e Dead Letter — como implementar corretamente

O padrão do Mirabel original (fila normal → fila retry com TTL+DLX → fila error) está certo. A correção necessária é **usar o header nativo `x-death` do RabbitMQ para contar tentativas**, em vez de uma variável local ao processo do worker (bug crítico #4 da auditoria).

```
Mensagem chega em orders.created
   ↓
Consumer processa → lança exceção
   ↓
nack() sem requeue
   ↓
DLX da fila "orders.created" aponta para exchange de retry
   ↓
Mensagem cai em orders.created.retry (TTL = ex. 5000ms)
   ↓
TTL expira → DLX da fila de retry aponta de volta para orders.created
   ↓
Consumer lê o header x-death da mensagem → conta quantas vezes já passou por lá
   ↓
Se count < max-attempts: processa de novo
Se count >= max-attempts: publica manualmente em orders.created.error e faz ack (remove da fila normal)
```

O header `x-death` é preenchido automaticamter pelo RabbitMQ toda vez que uma mensagem é morta por DLX — ele já contém `count`, `reason`, `queue`, `exchange` de origem. Ler esse header em vez de manter um contador próprio é mais simples e **correto por construção**, porque o contador vive no lado do broker, não no processo do worker (que pode reiniciar, escalar horizontalmente, etc. — um contador local não sobrevive a nada disso).

Exponential backoff: implementável com múltiplas filas de retry com TTLs crescentes (`retry.5s`, `retry.30s`, `retry.5m`), cada uma devolvendo para a fila normal via DLX — mais filas, mas sem depender de plugins externos (o plugin `rabbitmq-delayed-message-exchange` existe e resolve isso de forma mais elegante, mas exige instalação de plugin no broker, o que não pode ser assumido como disponível). Recomendação: suportar fixed-delay no Core (fila única de retry) e documentar exponential backoff via múltiplas filas como padrão avançado, com suporte opcional ao plugin de delayed exchange **se detectado no broker** (feature-detection, nunca hard requirement).

---

## 13. Idempotência — garantias reais

RabbitMQ com acks manuais garante **at-least-once delivery**, nunca exactly-once. Isso precisa ser dito exatamente assim na documentação do Mirabel, sem meio-termo: uma mensagem pode ser entregue mais de uma vez (crash do consumer entre processar e `ack`, falha de rede no `ack`, requeue manual) e o Mirabel **não pode e não deve prometer o contrário**.

O que o Mirabel pode ajudar a fazer:
- Expor o `messageId` de forma consistente e acessível no `Envelope`, para que a aplicação implemente sua própria deduplicação (ex.: tabela de IDs processados, chave em Redis) se precisar de idempotência real.
- Documentar claramente o padrão "idempotent consumer" (verificar se já processou antes de agir) como responsabilidade da aplicação.
- **Não** implementar deduplicação embutida no Core — isso exigiria estado persistente (banco, Redis) que o Core explicitamente não deve gerenciar (viola a seção 26: "não implementar banco").

---

## 14. Workers

Requisitos para produção, todos ausentes hoje:

- **Graceful shutdown**: registrar handlers de `SIGTERM`/`SIGINT` via `pcntl_signal()` (requer extensão `pcntl`, comum em CLI mas ausente por padrão em algumas distros — checar e falhar de forma clara se ausente, em vez de silenciosamente não tratar sinais). Ao receber o sinal, parar de aceitar novas mensagens, terminar de processar a mensagem em andamento, e só então fechar canal/conexão.
- **Reconexão automática**: se a conexão cair, o worker deve tentar reconectar com backoff, não morrer.
- **`max messages` / `max runtime` / `idle timeout`**: parâmetros opcionais para o worker se auto-encerrar após N mensagens ou T segundos — útil para mitigar memory leaks de longa duração (comuns em workers PHP de vida longa, já que o PHP não foi desenhado originalmente para processos long-running) permitindo que o orquestrador (Kubernetes, supervisor) simplesmente reinicie o processo periodicamente.
- **Health checks**: o worker deve expor de alguma forma (arquivo de heartbeat, endpoint HTTP simples opcional, ou apenas um método `isHealthy()`) se está conectado e consumindo — para liveness/readiness probes do Kubernetes. O Mirabel não deve tentar rodar um servidor HTTP embutido; apenas expor o estado de forma que a aplicação decida como servir isso.
- **Prefetch/concorrência**: já parcialmente suportado hoje via `qos_prefetch_count`; manter e documentar melhor. Concorrência real (múltiplos processos) é responsabilidade do orquestrador (múltiplos pods/réplicas), não do Mirabel — não implementar pool de threads/processos dentro da lib.

Kubernetes deve permanecer puramente como ambiente de execução — o Mirabel garante que o processo se comporta bem quando morto/reiniciado (graceful shutdown, reconexão), mas não se envolve em orquestração, scaling ou service discovery.

---

## 15. Observabilidade

- **PSR-3 (`Psr\Log\LoggerInterface`)**: o Core aceita um logger opcional injetado (default: `Psr\Log\NullLogger`, que não faz nada). Nunca acoplar a Monolog ou `Illuminate\Log` diretamente — a integração Laravel faz esse binding.
- **OpenTelemetry**: suportar propagação de contexto de trace via headers da mensagem (ex.: `traceparent`, seguindo W3C Trace Context) é viável e de baixo custo — não requer dependência do SDK completo do OpenTelemetry no Core, só a convenção de headers. Instrumentação automática completa (criar spans automaticamente) pode ficar como pacote de integração opcional futuro (`mirabel/opentelemetry`), seguindo o mesmo padrão de `mirabel/laravel`.
- **Métricas** (retry count, queue depth, processing time): expor via eventos/callbacks internos simples (ex.: `onMessageProcessed`, `onMessageFailed` como hooks que a aplicação pode registrar) em vez de embutir um cliente Prometheus/StatsD no Core — deixa a aplicação decidir onde essas métricas vão parar.

---

## 16. Configuração

> **Nota pós-decisão de DX (Seção 5):** a forma primária de configuração ficou definida como leitura lazy de variáveis `MB_RABBITMQ_*` via `getenv()`, sem bootstrap explícito — ver Seção 5. O conteúdo abaixo (`ConnectionConfig` explícito e `fromEnvironment()`) permanece válido como **API programática avançada**, disponível para quem precisa sobrescrever configuração (testes, múltiplos brokers, integrações Laravel/Symfony que traduzem sua própria config para isso por baixo), mas não é mais o caminho exigido no uso comum.

Ambas as formas do prompt original são úteis e não são mutuamente exclusivas:

```php
$config = new ConnectionConfig(
    host: 'localhost',
    port: 5672,
    username: 'guest',
    password: 'guest',
);
```

```php
$config = ConnectionConfig::fromEnvironment(); // lê variáveis de ambiente com prefixo MIRABEL_ ou customizável
```

Trade-off: `fromEnvironment()` é conveniente mas implícito (o `env()` do Laravel embutido no código, hoje, é exatamente esse tipo de conveniência que causou o acoplamento crítico #1). A versão framework-agnostic de `fromEnvironment()` deve ler `getenv()`/`$_ENV` diretamente do PHP puro (sem depender do helper `env()` do Laravel, que é açúcar sintático de framework), documentando claramente quais variáveis são lidas. Isso mantém a conveniência sem reintroduzir o acoplamento.

---

## 17. Dependency Injection

O Core não deve assumir nenhum container. `Bus::create($config)` e `Consumer::create($config)` funcionam como factories estáticas sem DI nenhum. Para quem usa container (PHP-DI, Symfony DI, container customizado), basta registrar `ConnectionConfig` e as classes concretas do Core como serviços normais — como todas essas classes recebem dependências via construtor/parâmetros explícitos (sem singletons globais escondidos, sem `config()` global), autowiring funciona de graça na maioria dos containers modernos.

---

## 18. PSR

- **PSR-4**: autoload, obrigatório, já usado hoje.
- **PSR-3**: logger opcional, conforme seção 15.
- **PSR-11 (Container)**: **não depender diretamente** — o Core não deve exigir um container. `mirabel/laravel` e `mirabel/symfony` podem consumir PSR-11 internamente se fizer sentido para seus respectivos bindings, mas isso é decisão de cada integração, não do Core.
- **PSR-14 (Event Dispatcher)**: avaliar como opcional para os hooks de observabilidade (seção 15) — se a aplicação já tem um dispatcher PSR-14, os hooks internos do Mirabel podem disparar eventos nele. Não obrigatório; hooks simples (callbacks) cobrem o caso comum sem forçar mais uma dependência.
- **PSR-18 (HTTP Client)**: não se aplica — Mirabel não faz chamadas HTTP.

---

## 19. API DX: simplicidade vs. controle completo

> **Nota pós-decisão de DX (Seção 5):** o raciocínio abaixo (parâmetros nomeados opcionais) continua válido para o `publish()` do `Event` (ex.: override pontual de `routingKey`). O que mudou: a identidade de um `Worker` (fila, routing keys, retry) **não** vai como argumentos de método — vai em propriedades estáticas tipadas no topo da classe, para preservar o formato de "cabeçalho declarativo" do artigo original do autor. Ver Seção 5 para os exemplos finais.

O equilíbrio correto é **parâmetros nomeados opcionais com defaults sensatos**, que o PHP 8+ já suporta nativamente (o projeto já exige PHP com suporte a isso, ver seção 21):

```php
// Caso comum
$bus->publish(new OrderCreated(123));

// Caso avançado — mesma assinatura, mais argumentos nomeados
$bus->publish(
    message: new OrderCreated(123),
    exchange: 'orders',
    routingKey: 'order.created',
    options: new PublishOptions(persistent: true, confirmSelect: true),
);
```

Isso evita duas armadilhas comuns: (a) criar um `Bus` "simples" e um `AdvancedBus` separado (duplica API, confunde qual usar), e (b) forçar sempre todos os parâmetros (mata a simplicidade do caso comum). Um único método, argumentos nomeados opcionais, é o padrão que o próprio prompt original já sugere na seção 19 — confirmado como a escolha certa.

---

## 20. Testes e infraestrutura de CI

- **Unit tests**: `Bus`, `Consumer`, `Serializer`, `RetryPolicy` testados isoladamente com um `AmqpDriverInterface` fake — nenhum teste unitário deve exigir RabbitMQ real rodando.
- **Integration tests**: contra RabbitMQ real via **Testcontainers para PHP** (`testcontainers/testcontainers-php`) ou, alternativa mais simples de manter, um `docker-compose.yml` de teste com RabbitMQ + management plugin, subido no CI antes da suíte de integração.
- **Contract tests**: garantir que o `JsonSerializer` default é capaz de serializar/desserializar um conjunto de tipos de mensagem de teste sem perda.
- **Failure tests**: simular broker indisponível (parar o container no meio do teste) para validar reconexão.
- **Retry/DLQ tests**: publicar mensagem que sempre falha, validar que ela navega normal → retry → error dentro do número certo de tentativas, lendo o header `x-death` (validação direta do bug crítico #4 corrigido).
- **Reconnect tests**: matar a conexão a meio de um `consume()` ativo e validar que o worker se recupera.
- **CI proposto**: GitHub Actions com matriz de PHP 8.2/8.3/8.4 (e 8.5 quando estabilizar em CI, como o próprio `php-amqplib` já faz), rodando unit tests em todas as versões e integration tests com RabbitMQ como serviço do próprio GitHub Actions (`services:` no workflow, usando a imagem oficial `rabbitmq:management`).

---

## 21. Compatibilidade

Dados de suporte oficial do PHP em setembro/2026:

| Versão PHP | Lançamento | Suporte ativo até | Suporte de segurança até |
|---|---|---|---|
| 8.2 | dez/2022 | dez/2024 (encerrado) | dez/2026 |
| 8.3 | nov/2023 | dez/2025 (encerrado) | dez/2027 |
| 8.4 | nov/2024 | dez/2026 | dez/2028 |
| 8.5 | nov/2025 | dez/2027 | dez/2029 |

**Recomendação: PHP mínimo 8.2**, com CI testando 8.2, 8.3, 8.4 e 8.5. PHP 8.2 ainda tem quase três meses de suporte de segurança restante a partir de hoje (chega ao fim em dez/2026) e é a versão mínima que `php-amqplib` 3.x e o próprio ecossistema moderno (Symfony 7, Laravel 11/12) ainda cobrem amplamente — mais restritivo que isso (8.3+) cortaria uma fatia real de usuários ainda migrando; menos restritivo (8.1 ou anterior) já está fora de qualquer suporte de segurança e não deve ser alvo de uma biblioteca nova.

**RabbitMQ**: suportar as versões com suporte ativo da própria RabbitMQ (série 3.13+ e a série 4.x, que já é a atual de referência) — não há necessidade de testar contra versões EOL do broker.

**SemVer**: adotar estritamente. Dado que o histórico de tags do projeto (`v0.1.0` → `v1.0.5`) já indica que houve um `v1.0.0`, e a reescrita proposta aqui **quebra compatibilidade de API de forma completa e intencional** (troca de traits com `config()` global por classes com injeção explícita), isso deve ser lançado como uma major nova (`v2.0.0`), com o `CHANGELOG.md` explicitando claramente que não é upgrade incremental — é reescrita.

---

## 22. Performance

Não há indicação, nem no código nem no histórico, de que o Mirabel atual tenha sido testado sob carga — não há benchmark, não há métricas. Recomendações realistas sem prematuramente otimizar:

- **Reaproveitar conexão/canal** entre publicações (em vez de abrir/fechar TCP a cada `publish()`, como hoje) é a otimização de maior impacto e mais óbvia — não é micro-otimização, é corrigir um problema estrutural real medido em qualquer teste de carga trivial.
- **Publisher confirms têm custo de latência** (esperar o ack do broker) — devem ser opt-in, não default, para não penalizar quem não precisa dessa garantia.
- **Prefetch** já é configurável hoje; manter default conservador (ex. 1 ou um valor pequeno) para não sobrecarregar consumidores lentos, documentando como ajustar para throughput maior.
- **Batching de publish** (publicar várias mensagens no mesmo canal antes de esperar confirms) pode ser um recurso avançado, não Core.
- Não otimizar serialização prematuramente — `json_encode`/`json_decode` nativos do PHP já são implementados em C e rápidos o suficiente para a esmagadora maioria dos casos de uso de filas de mensagens (que não são, tipicamente, data pipelines de altíssimo volume).

---

## 23. Segurança

- **TLS**: suportar desde o Core via `AMQPSSLConnection` do `php-amqplib` (já disponível na lib), com `ConnectionConfig` aceitando opções de TLS (certificado, verificação de host) — hoje o Mirabel só usa `AMQPStreamConnection` sem TLS.
- **Credentials/secrets**: `ConnectionConfig` nunca deve logar a senha (cuidado ao implementar qualquer `__toString()`/debug de config). Recomendar explicitamente na documentação que segredos venham de variáveis de ambiente/vault, nunca hardcoded — mas isso é responsabilidade de uso, não algo que a lib impõe.
- **Deserialização seria o maior risco de segurança real** — reforça a decisão da seção 11 de nunca usar `unserialize()` nativo como default, exatamente porque mensagens de fila são, por definição, entrada não confiável (podem vir de outro serviço, potencialmente comprometido, ou de uma versão antiga da própria aplicação com payload malformado).
- **Mensagens malformadas**: o `Consumer` deve tratar falha de desserialização como uma falha normal de processamento (vai para retry/DLQ como qualquer outra exceção), nunca deixar uma exceção de parsing derrubar o processo do worker inteiro sem `nack`.
- **Autenticação/autorização**: delegadas inteiramente ao RabbitMQ (usuários, vhosts, permissions) — não é responsabilidade da biblioteca cliente reimplementar controle de acesso.

---

## 24. Estrutura de pacotes

Duas opções levantadas no prompt original:

```
A) mirabel/rabbitmq, mirabel/laravel, mirabel/symfony
B) mirabel/rabbitmq, mirabel/rabbitmq-laravel, mirabel/rabbitmq-symfony
```

**Recomendação: opção B** (`mirabel/rabbitmq-laravel`, `mirabel/rabbitmq-symfony`), por um motivo concreto: o namespace `mirabel/laravel` sugere (incorretamente) que é *a* integração Laravel oficial e única do "ecossistema Mirabel" como um todo, quando o projeto é especificamente sobre RabbitMQ e pode, em tese, um dia ter outros produtos sob o nome Mirabel não relacionados a filas. Prefixar com `rabbitmq-` deixa o escopo de cada pacote inequívoco só pelo nome, sem precisar ler a descrição. Isso é uma preferência defensável, não uma regra rígida — se o autor já pensa em "Mirabel" como uma marca guarda-chuva para várias libs, a opção A também é razoável, mas dado que hoje o projeto só existe no contexto de RabbitMQ, a opção B comunica melhor o escopo atual sem prometer mais do que existe.

---

## 25. Diferencial competitivo

Comparação direta:

| Biblioteca | Framework-agnostic no Core | DX simples no caso comum | Retry/DLQ embutido | Manutenção ativa (2026) |
|---|---|---|---|---|
| `php-amqplib` puro | ✅ (é a base, não framework) | ❌ — API de protocolo, verbosa por design | ❌ — precisa implementar manualmente | ✅ Ativa (v3.7.4, testa PHP 8.5) |
| `enqueue/amqp-lib` | ✅ | Parcial — API de interop, mais cerimônia | Parcial (via `amqp-tools`) | ⚠️ Saúde de manutenção baixa (54/100, 1 mantenedor ativo) |
| Symfony Messenger + transport AMQP | Não (acoplado ao Messenger/Symfony) | ✅ dentro do ecossistema Symfony | Parcial (Messenger tem retry próprio) | ✅ Ativa (mantida pelo core do Symfony) |
| Laravel Queue (driver RabbitMQ) | Não (acoplado ao Laravel Queue) | ✅ dentro do Laravel | Via Laravel Queue (não é DLX nativo do RabbitMQ) | ✅ Ativa (ecossistema Laravel) |
| **Mirabel (proposto)** | ✅ Core puro | ✅ Objetivo explícito de design | ✅ Nativo, correto (via `x-death`) | A construir |

**A resposta concreta a "por que Mirabel em vez de `php-amqplib` direto?"**: `php-amqplib` é — corretamente — uma implementação do protocolo AMQP 0-9-1, não uma biblioteca de aplicação. Ele não tem opinião sobre retry, DLQ, reconexão, serialização ou lifecycle de worker — e não deveria ter, esse não é o escopo dele. O diferencial concreto do Mirabel não é "API mais simples" em abstrato — é **entregar, prontas e testadas, as decisões de engenharia que todo mundo que usa `php-amqplib` em produção acaba tendo que implementar do zero de qualquer forma**: reconexão, o padrão correto de retry/DLQ via `x-death`, graceful shutdown, publisher confirms opcionais. Hoje, quem quer isso em PHP puro (fora de Symfony Messenger ou Laravel Queue) não tem uma opção madura e ativamente mantida — só `php-amqplib` cru (reimplementar tudo) ou `enqueue` (interop mais burocrático, saúde de manutenção baixa). Esse é o espaço real.

---

## 26. O que NÃO fazer

- **Não depender de Laravel no Core.** Já coberto extensivamente — é o objetivo central deste projeto de evolução.
- **Não implementar ORM ou acesso a banco.** Mesmo para idempotência (seção 13) ou health checks (seção 14), a lib expõe primitivas (message ID, hooks) e deixa a persistência para a aplicação.
- **Não virar um framework.** Sem sistema de rotas, sem DI container próprio, sem "Mirabel Kernel". O Mirabel é uma biblioteca de mensageria, não uma plataforma de aplicação.
- **Não implementar orquestração Kubernetes.** O Mirabel garante que o processo se comporta bem quando gerenciado por um orquestrador (sinais, health signal); ele não decide replica count, não faz service discovery, não gerencia deployments.
- **Não abstrair outros brokers (Kafka, Redis Streams, NATS, SQS).** Reforçado pelo próprio prompt original e mantido aqui sem ressalvas: cada broker de mensageria tem semânticas fundamentalmente diferentes (Kafka é log particionado, RabbitMQ é fila com roteamento; misturar as duas abstrações sob uma API comum tipicamente resulta em um mínimo denominador comum que serve mal aos dois). Foco total em RabbitMQ é o que permite ao Mirabel expor bem os recursos específicos do RabbitMQ (seção 8) em vez de escondê-los atrás de uma abstração genérica de "fila".
- **Não criar um sistema de filas próprio.** RabbitMQ já é o sistema de filas. O Mirabel organiza o *uso* dele, não reimplementa conceitos de fila em cima dele (ex.: não implementar uma fila em memória ou em banco como "fallback" — se o RabbitMQ está fora do ar, a aplicação deve saber disso, não ter esse fato escondido por um fallback silencioso).
- **Não esconder completamente conceitos do RabbitMQ.** O caso simples (`$bus->publish($event)`) não deve impedir o usuário avançado de configurar exchange type, DLX, TTL, prefetch, etc. explicitamente. Uma lib que "simplifica" escondendo permanentemente esses conceitos vira uma armadilha assim que o usuário precisa de algo um pouco fora do caminho feliz.
- **Não criar abstrações artificiais.** Especificamente: não modelar `Event`/`Command`/`Request`/`Response` como tipos distintos e obrigatórios no Core (seção 10) — RabbitMQ/AMQP não faz essa distinção, e forçá-la no transporte é impor uma opinião de modelagem de domínio que não é da lib de mensageria decidir.
- **Não suportar `ext-amqp` no MVP** (seção 9) — complexidade sem demanda comprovada hoje.

---

## 27. Roadmap

| Fase | Escopo | Esforço relativo | Riscos | Dependências | Critério de conclusão |
|---|---|---|---|---|---|
| **0 — Auditoria** | Este documento | Concluído | — | — | Este relatório aprovado/discutido |
| **1 — Core PHP** | `ConnectionConfig`, `ConnectionManager` (reconexão), interfaces base (`Contracts/`) | Médio | Definir bem as interfaces agora evita breaking changes cedo | Nenhuma | `composer require` funciona em projeto PHP puro sem erro, conecta e reconecta a um RabbitMQ local |
| **2 — RabbitMQ básico** | `Topology` (exchange/queue/bind), driver `php-amqplib` atrás de `AmqpDriverInterface` | Médio | — | Fase 1 | Declarar exchange/queue/binding via API do Core, validado contra RabbitMQ real |
| **3 — Publisher/Consumer** | `Bus::publish()`, `Consumer::consume()`, `Envelope`, `JsonSerializer` | Médio-Alto | Acertar a API pública aqui é crítico — é o que os usuários veem primeiro | Fases 1-2 | Exemplo end-to-end (publish → consume) funcionando em PHP puro, com teste de integração |
| **4 — Retry/DLQ** | Topologia normal→retry→error, leitura de `x-death`, `RetryPolicy` | Médio | Maior risco de bug sutil (é onde o Mirabel original quebrou) | Fase 3 | Teste de integração comprova contagem correta de tentativas via `x-death`, mensagem cai em DLQ no attempt certo |
| **5 — Workers robustos** | Graceful shutdown (SIGTERM/SIGINT), max messages/runtime, reconexão em consumo ativo | Médio | `pcntl` pode não estar disponível em todo ambiente — checar e falhar com mensagem clara | Fase 3 | Worker sobrevive a `docker stop`, termina mensagem em andamento antes de sair |
| **6 — Observabilidade** | PSR-3 logging opcional, hooks de evento (`onMessageProcessed`/`onMessageFailed`), headers de trace context | Baixo-Médio | — | Fases 3-5 | Logger injetado recebe eventos de publish/consume/retry/failure |
| **7 — RabbitMQ avançado** | Quorum queues, priority queues, single active consumer (como opções de `Topology`) | Baixo-Médio | Quorum queues têm restrições próprias (nem todo argumento clássico se aplica) — documentar bem | Fase 2 | Flags de topologia avançada testadas contra RabbitMQ 3.13+/4.x |
| **8 — Laravel integration** | Pacote `mirabel/rabbitmq-laravel`: ServiceProvider, config, Facade opcional, Artisan command | Médio | Garantir que nada disso vaza de volta pro Core | Fase 5 (workers prontos para o Artisan command rodar) | App Laravel de exemplo publicando/consumindo via `mirabel:consume` |
| **9 — Symfony integration** | Pacote `mirabel/rabbitmq-symfony`: bundle simples (serviços + command) | Médio | Decidir se vale investir em transport nativo do Messenger (ver seção 7) | Fase 5 | App Symfony de exemplo equivalente ao de Laravel |
| **10 — Production hardening** | TLS, publisher confirms, testes de carga básicos, revisão de segurança, CI completo (matriz PHP 8.2-8.5, integration tests) | Médio-Alto | — | Todas anteriores | CI verde em PHP 8.2/8.3/8.4/8.5, suíte de integration tests passando, TLS testado |

---

## 28. MVP

O MVP proposto no prompt original (publish, consume, routing, serialization, ack, retry, DLQ, reconnect, logging) é ambicioso demais para um "menor produto viável" — é, na prática, as Fases 1 a 6 inteiras.

**MVP real e honesto: Fases 1, 2, 3 e metade da Fase 5 (graceful shutdown apenas, sem max messages/runtime ainda).** Ou seja:

```
PHP puro → Mirabel → RabbitMQ → Consumer
```

com **publish, consume, routing, serialization (JSON), ack/nack/reject, graceful shutdown**.

**Retry/DLQ fica fora do MVP inicial, por decisão explícita**, pelo seguinte motivo: é exatamente a parte que estava quebrada no Mirabel original (bug crítico #4), é a parte de maior risco de bug sutil, e um MVP sem retry/DLQ **ainda é genuinamente útil e publicável** — um dev pode usar `publish`/`consume` puro e implementar seu próprio retry manualmente enquanto isso amadurece. Lançar Fases 1-3 como `v2.0.0-beta`, validar em uso real (dogfooding), e só então lançar Fase 4 (retry/DLQ) como `v2.1.0` reduz o risco de reintroduzir o mesmo tipo de bug sob pressão de "MVP completo". Reconexão (parte da Fase 1/`ConnectionManager`) **entra no MVP**, diferente do que a lista do prompt sugere separar — sem reconexão básica, o Consumer não é seguro para nenhum uso real, nem o mais simples.

---

## 29. Riscos

- **Risco de escopo**: a lista de 29 seções do prompt original é abrangente o suficiente para, se tratada como "tudo precisa entrar na v2.0", nunca ser lançada. Mitigação: o MVP definido na seção 28 é deliberadamente menor que a lista completa.
- **Risco técnico concentrado no retry/DLQ**: é onde o projeto original já falhou uma vez (bug do contador). Mitigação: testes de integração específicos para navegação normal→retry→error via `x-death` **antes** de considerar a Fase 4 concluída, não depois.
- **Risco de adoção**: o nome/marca "Mirabel" ainda não tem tração de comunidade (o próprio README original reconhece isso). Reescrever como v2.0.0 é, na prática, lançar um projeto novo sob um nome existente — vale comunicar isso claramente no changelog/anúncio, não tratar como "mais uma versão".
- **Risco de manter `ext-amqp` fora do escopo permanentemente**: se um caso de uso real de altíssima performance aparecer depois, adicionar um segundo driver depois que a interface `AmqpDriverInterface` já está pública é mais barato do que projetar para isso agora sem necessidade comprovada — risco aceito conscientemente (ver seção 9).
- **Risco de dependência única (`php-amqplib`)**: mitigado pela interface `AmqpDriverInterface` (seção 9) — se `php-amqplib` parar de ser mantida no futuro, trocar de driver não exige reescrever `Bus`/`Consumer`.

---

## 30. Recomendação técnica final

Ressuscitar o Mirabel vale a pena, mas como **reescrita v2.0.0 sob o mesmo nome**, não como refatoração incremental do código de `src/` hoje existente — os problemas críticos (acoplamento a `config()` do Laravel espalhado pelo Core, contador de retry quebrado, ausência total de testes reais, ausência de reconexão) estão profundamente entrelaçados com a estrutura atual de traits, e corrigi-los um por um dentro da estrutura existente custaria, honestamente, mais esforço do que projetar certo desde o início com o conhecimento de domínio já adquirido.

A oportunidade de mercado é real e específica: existe hoje um vácuo entre "usar `php-amqplib` cru e reimplementar reconexão/retry/DLQ na mão em todo projeto" e "estar preso a Laravel Queue ou Symfony Messenger para ter isso pronto". O Mirabel, feito framework-agnostic desde o Core, com o padrão de retry/DLQ corrigido (via `x-death` nativo em vez de contador manual) e reconexão de verdade, preenche esse vácuo de forma concreta — não com uma promessa genérica de "API mais simples".

O caminho recomendado: MVP enxuto (Fases 1-3 + graceful shutdown), validado com uso real antes de adicionar retry/DLQ (Fase 4), com a integração Laravel chegando só depois do Core estar provado standalone — inclusive como forma de garantir, na prática e não só na intenção, que o Core nunca precisou de Laravel para funcionar.
