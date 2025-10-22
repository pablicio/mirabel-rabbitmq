#!/bin/bash

# Script para executar todos os testes do Mirabel RabbitMQ
# Usage: ./run-tests.sh [opcao]
# Opções:
#   unit       - Apenas testes unitários
#   examples   - Apenas exemplos
#   full       - Testes + Exemplos
#   coverage   - Testes com cobertura

set -e

# Cores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Banner
echo -e "${BLUE}"
echo "╔══════════════════════════════════════════════════════════╗"
echo "║                                                          ║"
echo "║         Mirabel RabbitMQ - Test Suite v2.0              ║"
echo "║                                                          ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo -e "${NC}"

# Verifica se RabbitMQ está rodando
echo -e "${YELLOW}🔍 Verificando RabbitMQ...${NC}"
if ! nc -z localhost 5672 2>/dev/null; then
    echo -e "${RED}❌ RabbitMQ não está rodando na porta 5672${NC}"
    echo -e "${YELLOW}💡 Inicie o RabbitMQ com: docker run -d -p 5672:5672 -p 15672:15672 rabbitmq:3-management${NC}"
    exit 1
fi
echo -e "${GREEN}✅ RabbitMQ está rodando${NC}\n"

# Função para executar testes unitários
run_unit_tests() {
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}🧪 Executando Testes Unitários${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}\n"
    
    if [ -f "vendor/bin/phpunit" ]; then
        ./vendor/bin/phpunit --colors=always
        echo -e "\n${GREEN}✅ Testes unitários concluídos!${NC}\n"
    else
        echo -e "${RED}❌ PHPUnit não encontrado. Execute: composer install${NC}"
        exit 1
    fi
}

# Função para executar testes com cobertura
run_coverage() {
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}📊 Executando Testes com Cobertura${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}\n"
    
    if [ -f "vendor/bin/phpunit" ]; then
        ./vendor/bin/phpunit --coverage-html coverage --colors=always
        echo -e "\n${GREEN}✅ Relatório de cobertura gerado em: coverage/index.html${NC}\n"
    else
        echo -e "${RED}❌ PHPUnit não encontrado. Execute: composer install${NC}"
        exit 1
    fi
}

# Função para executar exemplos
run_examples() {
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}🎯 Executando Exemplos${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}\n"
    
    if [ -f "examples/complete_examples.php" ]; then
        php examples/complete_examples.php
        echo -e "\n${GREEN}✅ Exemplos executados com sucesso!${NC}\n"
    else
        echo -e "${RED}❌ Arquivo de exemplos não encontrado${NC}"
        exit 1
    fi
}

# Função para executar comunicação entre serviços
run_communication_example() {
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}💬 Executando Exemplo de Comunicação${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}\n"
    
    echo -e "${YELLOW}💡 Para ver a comunicação completa, execute em terminais separados:${NC}"
    echo -e "   Terminal 1: php examples/consumer_product.php"
    echo -e "   Terminal 2: php examples/consumer_store.php"
    echo -e "   Terminal 3: php examples/communication_example.php\n"
    
    if [ -f "examples/communication_example.php" ]; then
        php examples/communication_example.php
        echo -e "\n${GREEN}✅ Exemplo de comunicação executado!${NC}\n"
    else
        echo -e "${RED}❌ Arquivo de exemplo não encontrado${NC}"
        exit 1
    fi
}

# Função para exibir estatísticas
show_stats() {
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}📊 Estatísticas do Projeto${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}\n"
    
    echo -e "${GREEN}Arquivos PHP:${NC}"
    find src -name "*.php" | wc -l | xargs echo "  src/:"
    find tests -name "*.php" 2>/dev/null | wc -l | xargs echo "  tests/:" || echo "  tests/: 0"
    find examples -name "*.php" 2>/dev/null | wc -l | xargs echo "  examples/:" || echo "  examples/: 0"
    
    echo -e "\n${GREEN}Linhas de Código:${NC}"
    find src -name "*.php" -exec cat {} \; | wc -l | xargs echo "  src/:"
    find tests -name "*.php" -exec cat {} \; 2>/dev/null | wc -l | xargs echo "  tests/:" || echo "  tests/: 0"
    
    echo ""
}

# Parse argumentos
case "${1:-full}" in
    unit)
        run_unit_tests
        ;;
    examples)
        run_examples
        ;;
    communication)
        run_communication_example
        ;;
    coverage)
        run_coverage
        ;;
    full)
        run_unit_tests
        run_examples
        show_stats
        ;;
    *)
        echo -e "${RED}Opção inválida: $1${NC}"
        echo -e "Uso: $0 [unit|examples|communication|coverage|full]"
        exit 1
        ;;
esac

echo -e "${GREEN}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║                                                          ║${NC}"
echo -e "${GREEN}║              ✅ Testes Concluídos com Sucesso!            ║${NC}"
echo -e "${GREEN}║                                                          ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════╝${NC}"
