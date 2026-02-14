<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq;

use Pablicio\MirabelRabbitmq\Contracts\SerializerInterface;
use Psr\Log\LoggerInterface;

class RabbitMQClientBuilder
{
    private array $config = [];
    private ?SerializerInterface $serializer = null;
    private ?LoggerInterface $logger = null;

    /**
     * Cria uma nova instância do builder
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Define o host
     */
    public function host(string $host): self
    {
        $this->config['host'] = $host;
        return $this;
    }

    /**
     * Define a porta
     */
    public function port(int $port): self
    {
        $this->config['port'] = $port;
        return $this;
    }

    /**
     * Define o usuário
     */
    public function user(string $user): self
    {
        $this->config['user'] = $user;
        return $this;
    }

    /**
     * Define a senha
     */
    public function password(string $password): self
    {
        $this->config['password'] = $password;
        return $this;
    }

    /**
     * Define o vhost
     */
    public function vhost(string $vhost): self
    {
        $this->config['vhost'] = $vhost;
        return $this;
    }

    /**
     * Define o heartbeat
     */
    public function heartbeat(int $seconds): self
    {
        $this->config['heartbeat'] = $seconds;
        return $this;
    }

    /**
     * Define timeout de conexão
     */
    public function connectionTimeout(float $seconds): self
    {
        $this->config['connection_timeout'] = $seconds;
        return $this;
    }

    /**
     * Define timeout de leitura/escrita
     */
    public function readWriteTimeout(float $seconds): self
    {
        $this->config['read_write_timeout'] = $seconds;
        return $this;
    }

    /**
     * Define keepalive
     */
    public function keepalive(bool $enabled): self
    {
        $this->config['keepalive'] = $enabled;
        return $this;
    }

    /**
     * Define máximo de tentativas de reconexão
     */
    public function maxReconnectAttempts(int $attempts): self
    {
        $this->config['max_reconnect_attempts'] = $attempts;
        return $this;
    }

    /**
     * Define delay entre reconexões
     */
    public function reconnectDelay(int $seconds): self
    {
        $this->config['reconnect_delay'] = $seconds;
        return $this;
    }

    /**
     * Define máximo de canais no pool
     */
    public function maxChannels(int $channels): self
    {
        $this->config['max_channels'] = $channels;
        return $this;
    }

    /**
     * Define exchange padrão
     */
    public function defaultExchange(string $exchange): self
    {
        $this->config['default_exchange'] = $exchange;
        return $this;
    }

    /**
     * Define o serializer
     */
    public function serializer(SerializerInterface $serializer): self
    {
        $this->serializer = $serializer;
        return $this;
    }

    /**
     * Define o logger
     */
    public function logger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Carrega configuração de um array
     */
    public function fromArray(array $config): self
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    /**
     * Carrega configuração de arquivo
     */
    public function fromFile(string $path): self
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("Configuration file not found: {$path}");
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        
        $config = match ($extension) {
            'php' => require $path,
            'json' => json_decode(file_get_contents($path), true),
            'env' => $this->parseEnvFile($path),
            default => throw new \InvalidArgumentException("Unsupported configuration file format: {$extension}"),
        };

        return $this->fromArray($config);
    }

    /**
     * Carrega configuração do Laravel
     */
    public function fromLaravel(string $connection = 'default'): self
    {
        if (!function_exists('config')) {
            throw new \RuntimeException('Laravel config helper not available');
        }

        $config = config("rabbitmq.connections.{$connection}");
        
        if (!$config) {
            throw new \InvalidArgumentException("RabbitMQ connection not found: {$connection}");
        }

        return $this->fromArray($config);
    }

    /**
     * Configuração rápida para desenvolvimento local
     */
    public function localhost(): self
    {
        return $this
            ->host('localhost')
            ->port(5672)
            ->user('guest')
            ->password('guest')
            ->vhost('/');
    }

    /**
     * Constrói o cliente
     */
    public function build(): RabbitMQClient
    {
        // Valida configuração mínima
        $this->validateConfig();

        // Define valores padrão
        $this->setDefaults();

        return new RabbitMQClient(
            $this->config,
            $this->serializer,
            $this->logger
        );
    }

    /**
     * Valida configuração mínima
     */
    private function validateConfig(): void
    {
        $required = ['host', 'port', 'user', 'password'];
        
        foreach ($required as $key) {
            if (!isset($this->config[$key])) {
                throw new \InvalidArgumentException("Missing required configuration: {$key}");
            }
        }
    }

    /**
     * Define valores padrão
     */
    private function setDefaults(): void
    {
        $defaults = [
            'vhost' => '/',
            'heartbeat' => 60,
            'connection_timeout' => 3.0,
            'read_write_timeout' => 3.0,
            'keepalive' => true,
            'max_reconnect_attempts' => 5,
            'reconnect_delay' => 2,
            'max_channels' => 10,
            'default_exchange' => '',
        ];

        foreach ($defaults as $key => $value) {
            if (!isset($this->config[$key])) {
                $this->config[$key] = $value;
            }
        }
    }

    /**
     * Parse arquivo .env
     */
    private function parseEnvFile(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $config = [];

        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove aspas
                $value = trim($value, '"\'');
                
                // Converte para snake_case
                $key = strtolower(str_replace('RABBITMQ_', '', $key));
                $key = str_replace('_', '_', $key);
                
                $config[$key] = $value;
            }
        }

        return $config;
    }
}
