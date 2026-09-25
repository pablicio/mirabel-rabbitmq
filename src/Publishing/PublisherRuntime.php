<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Publishing;

use Mirabel\RabbitMQ\Connection\ConnectionFactoryInterface;
use Mirabel\RabbitMQ\ConnectionConfig;
use PhpAmqpLib\Message\AMQPMessage;

final class PublisherRuntime
{
	/** @var array<int, array{connection: object, channel: object}> */
	private static array $sessions = [];

	public static function publish(
		ConnectionFactoryInterface $factory,
		ConnectionConfig $config,
		AMQPMessage $message,
		string $routingKey,
	): void {
		$key = implode(':', [
			$factory::class,
			$config->host,
			$config->port,
			$config->user,
			$config->vhost,
			$config->exchange,
			$config->exchangeType,
		]);

		if (!isset(self::$sessions[$key])) {
			$connection = $factory->connect($config);
			$channel = $connection->channel();
			$channel->exchange_declare($config->exchange, $config->exchangeType, false, true, false);
			if ($config->publisherConfirms) {
				$channel->confirm_select();
			}
			self::$sessions[$key] = ['connection' => $connection, 'channel' => $channel];
		}

		try {
			$channel = self::$sessions[$key]['channel'];
			$channel->basic_publish($message, $config->exchange, $routingKey);
			if ($config->publisherConfirms) {
				$channel->wait_for_pending_acks();
			}
		} catch (\Throwable $exception) {
			self::close($key);
			throw $exception;
		}
	}

	public static function closeAll(): void
	{
		foreach (array_keys(self::$sessions) as $key) {
			self::close($key);
		}
	}

	private static function close(string $key): void
	{
		if (!isset(self::$sessions[$key])) {
			return;
		}

		$session = self::$sessions[$key];
		unset(self::$sessions[$key]);

		try {
			$session['channel']->close();
		} finally {
			$session['connection']->close();
		}
	}
}