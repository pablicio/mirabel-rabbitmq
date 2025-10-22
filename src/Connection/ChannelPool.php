<?php

declare(strict_types=1);

namespace Pablicio\MirabelRabbitmq\Connection;

use PhpAmqpLib\Channel\AMQPChannel;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ChannelPool
{
    private ConnectionManager $connectionManager;
    private array $channels = [];
    private array $channelStats = [];
    private int $maxChannels;
    private LoggerInterface $logger;

    public function __construct(
        ConnectionManager $connectionManager,
        int $maxChannels = 10,
        ?LoggerInterface $logger = null
    ) {
        $this->connectionManager = $connectionManager;
        $this->maxChannels = $maxChannels;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Obtém um canal do pool ou cria um novo
     */
    public function getChannel(?string $channelId = null): AMQPChannel
    {
        $channelId = $channelId ?? 'default';

        // Se já existe um canal válido, retorna ele
        if (isset($this->channels[$channelId]) && $this->isChannelValid($channelId)) {
            $this->channelStats[$channelId]['reuses']++;
            return $this->channels[$channelId];
        }

        // Verifica limite de canais
        if (count($this->channels) >= $this->maxChannels) {
            $this->logger->warning('Channel pool limit reached', [
                'max_channels' => $this->maxChannels,
                'current_channels' => count($this->channels),
            ]);
            
            // Remove o canal menos usado
            $this->removeLeastUsedChannel();
        }

        // Cria novo canal
        return $this->createChannel($channelId);
    }

    /**
     * Cria um novo canal
     */
    private function createChannel(string $channelId): AMQPChannel
    {
        try {
            $connection = $this->connectionManager->getConnection();
            $channel = $connection->channel();

            $this->channels[$channelId] = $channel;
            $this->channelStats[$channelId] = [
                'created_at' => time(),
                'reuses' => 0,
                'last_used' => time(),
            ];

            $this->logger->info('Created new channel', [
                'channel_id' => $channelId,
                'total_channels' => count($this->channels),
            ]);

            return $channel;

        } catch (\Exception $e) {
            $this->logger->error('Failed to create channel', [
                'channel_id' => $channelId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Verifica se o canal ainda é válido
     */
    private function isChannelValid(string $channelId): bool
    {
        if (!isset($this->channels[$channelId])) {
            return false;
        }

        $channel = $this->channels[$channelId];

        try {
            return $channel->is_open();
        } catch (\Exception $e) {
            $this->logger->warning('Channel validation failed', [
                'channel_id' => $channelId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Remove o canal menos usado
     */
    private function removeLeastUsedChannel(): void
    {
        if (empty($this->channelStats)) {
            return;
        }

        $leastUsed = null;
        $minReuses = PHP_INT_MAX;

        foreach ($this->channelStats as $channelId => $stats) {
            if ($stats['reuses'] < $minReuses) {
                $minReuses = $stats['reuses'];
                $leastUsed = $channelId;
            }
        }

        if ($leastUsed !== null) {
            $this->closeChannel($leastUsed);
        }
    }

    /**
     * Fecha um canal específico
     */
    public function closeChannel(string $channelId): void
    {
        if (isset($this->channels[$channelId])) {
            try {
                $this->channels[$channelId]->close();
                $this->logger->info('Closed channel', ['channel_id' => $channelId]);
            } catch (\Exception $e) {
                $this->logger->warning('Error closing channel', [
                    'channel_id' => $channelId,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                unset($this->channels[$channelId]);
                unset($this->channelStats[$channelId]);
            }
        }
    }

    /**
     * Fecha todos os canais
     */
    public function closeAllChannels(): void
    {
        $channelIds = array_keys($this->channels);
        
        foreach ($channelIds as $channelId) {
            $this->closeChannel($channelId);
        }

        $this->logger->info('Closed all channels');
    }

    /**
     * Retorna estatísticas do pool
     */
    public function getStats(): array
    {
        return [
            'total_channels' => count($this->channels),
            'max_channels' => $this->maxChannels,
            'channels' => $this->channelStats,
        ];
    }

    /**
     * Destrutor - fecha todos os canais
     */
    public function __destruct()
    {
        $this->closeAllChannels();
    }
}
