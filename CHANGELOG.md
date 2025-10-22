# Changelog

Todas as mudanças notáveis neste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/),
e este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [2.0.0] - 2025-10-22

### 🎉 Adicionado

#### Gerenciamento de Conexão
- ✨ **ConnectionManager**: Gerenciamento robusto de conexões com reconexão automática
- ✨ **Reconexão Automática**: Estratégia configurável de reconexão com backoff
- ✨ **Heartbeat AMQP**: Detecção rápida de falhas de conexão
- ✨ **Callbacks de Conexão**: `onConnection()` e `onDisconnection()` para hooks customizados
- ✨ **Estatísticas de Conexão**: Monitoramento em tempo real do estado da conexão

#### Pool de Canais
- ✨ **ChannelPool**: Pool inteligente de canais AMQP para reutilização
- ✨ **Gerenciamento Automático**: Criação e fechamento automático de canais
- ✨ **Limite Configurável**: Controle sobre número máximo de canais
- ✨ **Estatísticas do Pool**: Métricas de uso e performance

#### Publisher
- ✨ **Publisher Confirms**: Garantia de entrega com confirmações do broker
- ✨ **Batch Publishing**: Publicação eficiente de múltiplas mensagens
- ✨ **Confirmação em Lote**: Aguarda confirmação de lotes completos
- ✨ **Timeout Configurável**: Controle sobre tempo de espera de confirmações
- ✨ **Handlers ACK/NACK**: Callbacks para confirmações positivas e negativas

#### Consumer
- ✨ **Retry Automático**: Sistema de retry com delay configurável
- ✨ **Estratégias de Erro**: Múltiplas estratégias para tratamento de falhas
- ✨ **Graceful Shutdown**: Desligamento seguro com signal handlers (SIGTERM/SIGINT)
- ✨ **Auto ACK/NACK**: Retorno simplificado de callbacks (true/false)
- ✨ **Estatísticas de Consumo**: Métricas detalhadas de processamento

#### Dead Letter Queues
- ✨ **DeadLetterQueueManager**: Gerenciamento completo de DLQs
- ✨ **Criação Automática**: Setup automático de DLX e DLQ
- ✨ **Retry Multi-Nível**: Estrutura de retry com delays exponenciais
- ✨ **Reprocessamento**: Ferramentas para reprocessar mensagens da DLQ
- ✨ **Purge de DLQ**: Limpeza controlada de mensagens descartadas

#### API e Configuração
- ✨ **RabbitMQClient**: API unificada e simplificada
- ✨ **RabbitMQClientBuilder**: Builder pattern para configuração fluente
- ✨ **Configuração Centralizada**: Arquivo de config único
- ✨ **Múltiplas Fontes**: Suporte a array, arquivo, Laravel config
- ✨ **Presets**: Configurações rápidas (`localhost()`, etc)

#### Quality of Service
- ✨ **QoS Configurável**: Controle fino sobre prefetch count/size
- ✨ **Por Canal**: QoS específico para cada canal
- ✨ **Global ou Local**: Aplicação em nível de canal ou conexão

#### Serialização
- ✨ **SerializationException**: Exceção específica para erros de serialização
- ✨ **Interface Extensível**: Fácil criação de serializers customizados
- ✨ **Content-Type Automático**: Detecta tipo de conteúdo pelo serializer

#### Monitoramento
- ✨ **Estatísticas Completas**: Métricas detalhadas de todos os componentes
- ✨ **Logging Estruturado**: Logs detalhados com contexto
- ✨ **PSR-3 Logger**: Compatível com qualquer logger PSR-3

#### Documentação
- ✨ **README Completo**: Documentação abrangente com exemplos
- ✨ **Guia de Migração**: MIGRATION.md com padrões de migração
- ✨ **Exemplos Práticos**: Pasta examples/ com casos de uso completos
- ✨ **Changelog**: Histórico detalhado de mudanças

### 🔧 Modificado

- ♻️ **JsonSerializer**: Agora lança `SerializationException` em vez de `RabbitMQException`
- ♻️ **Publisher**: Otimizado para usar canal único por operação
- ♻️ **Arquitetura**: Separação clara entre componentes de baixo e alto nível

### 🐛 Corrigido

- 🐛 **Serialization Test**: Correção na expectativa de exceção nos testes
- 🐛 **Channel Reuse**: Corrigido problema de múltiplas chamadas ao método `channel()`
- 🐛 **Memory Leaks**: Prevenção de vazamentos com destrutor adequado

### 🔒 Segurança

- 🔐 **SSL/TLS Support**: Suporte completo a conexões seguras
- 🔐 **Credential Management**: Melhores práticas para gerenciamento de credenciais

### ⚡ Performance

- ⚡ **Batch Operations**: Redução de overhead com operações em lote
- ⚡ **Channel Pooling**: Reutilização eficiente de canais
- ⚡ **Connection Reuse**: Conexões persistentes com heartbeat

### 📝 Documentação

- 📖 **Complete README**: Documentação abrangente em português
- 📖 **Migration Guide**: Guia detalhado de migração da v1.x
- 📖 **Code Examples**: 10+ exemplos práticos de uso
- 📖 **API Reference**: Documentação inline completa
- 📖 **Best Practices**: Seção de melhores práticas e patterns

## [1.0.0] - 2025-10-20

### Adicionado
- 🎉 Versão inicial da biblioteca
- ✨ Publisher básico com suporte a exchanges
- ✨ Consumer básico com callbacks
- ✨ Serializer JSON
- ✨ Service Provider para Laravel
- ✨ Configuração básica
- ✨ Testes unitários

### Recursos Iniciais
- Publisher com fluent API
- Consumer com callbacks
- JSON serialization
- Laravel integration
- Basic logging
- Unit tests

---

## Tipos de Mudanças

- ✨ `Adicionado` para novas funcionalidades
- 🔧 `Modificado` para mudanças em funcionalidades existentes
- ⚠️ `Deprecated` para funcionalidades que serão removidas
- 🗑️ `Removido` para funcionalidades removidas
- 🐛 `Corrigido` para correções de bugs
- 🔒 `Segurança` para correções de vulnerabilidades
- ⚡ `Performance` para melhorias de performance
- 📝 `Documentação` para mudanças na documentação

## Links

- [2.0.0] - 2025-10-22
- [1.0.0] - 2025-10-20

## Roadmap

### v2.1.0 (Planejado)
- [ ] Circuit Breaker pattern
- [ ] Message TTL automático
- [ ] Priority queues
- [ ] Message tracing
- [ ] Prometheus metrics exporter
- [ ] Async publishing com promises
- [ ] Stream processing support

### v2.2.0 (Planejado)
- [ ] Saga pattern support
- [ ] Event sourcing helpers
- [ ] CQRS pattern integration
- [ ] GraphQL subscription support
- [ ] WebSocket bridge
- [ ] Admin UI dashboard

### v3.0.0 (Futuro)
- [ ] Breaking: PHP 8.2+ required
- [ ] Full async/await support com Revolt
- [ ] Native fibers support
- [ ] RabbitMQ Streams API
- [ ] Cluster support
- [ ] High availability features
