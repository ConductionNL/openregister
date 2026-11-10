<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\Support\WebhookDeliveryStores;
use OCA\OpenRegister\BackgroundJob\WebhookRetryJob;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\WebhookDelivery;
use OCA\OpenRegister\Service\WebhookService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Characterisation test: pins the exact log trail (level, message and context,
 * without the source line) of WebhookRetryJob::run across every branch.
 */
class WebhookRetryJobLogTrailTest extends TestCase {

	/**
	 * Build a logger that records every call without its `line` key.
	 *
	 * @return AbstractLogger
	 */
	private function recordingLogger(): AbstractLogger {
		return new class extends AbstractLogger {
			/** @var array<int, array{0: string, 1: string, 2: array}> */
			public array $trail = [];

			public function log($level, $message, array $context = []): void {
				unset($context['line']);
				if (isset($context['timestamp']) === true) {
					$context['timestamp'] = 'ts';
				}
				$this->trail[] = [$level, (string) $message, $context];
			}
		};
	}

	private function log(int $id, int $webhookId, int $attempt): WebhookLog {
		$log = new WebhookLog();
		$log->setId($id);
		$log->setWebhook($webhookId);
		$log->setAttempt($attempt);
		$log->setEventClass('Evt' . $id);
		$log->setPayloadArray(['n' => $id]);
		return $log;
	}

	private function webhook(int $id, bool $enabled, int $maxRetries): Webhook {
		$webhook = new Webhook();
		$webhook->setId($id);
		$webhook->setEnabled($enabled);
		$webhook->setMaxRetries($maxRetries);
		return $webhook;
	}

	private function runJob(WebhookRetryJob $job): void {
		$method = new \ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}

	public function testLogTrailWhenNothingToRetry(): void {
		$logger = $this->recordingLogger();
		$logMapper = $this->createMock(WebhookLogMapper::class);
		$logMapper->method('findFailedForRetry')->willReturn([]);
		$job = new WebhookRetryJob(
			$this->createMock(ITimeFactory::class),
			new WebhookDeliveryStores($this->createMock(WebhookMapper::class), $logMapper),
			$this->createMock(WebhookService::class),
			$logger
		);

		$this->runJob($job);

		$file = (new \ReflectionClass(WebhookRetryJob::class))->getFileName();
		$this->assertSame(
			[
				['debug', '[WebhookRetryJob] Checking for webhook retries', ['file' => $file, 'timestamp' => 'ts']],
				['debug', '[WebhookRetryJob] No webhook retries needed', ['file' => $file]],
			],
			$logger->trail
		);
	}

	public function testLogTrailAcrossEveryBranch(): void {
		$logger = $this->recordingLogger();
		$logs = [
			$this->log(1, 10, 1),
			$this->log(2, 20, 5),
			$this->log(3, 30, 2),
			$this->log(4, 40, 3),
			$this->log(5, 50, 0),
		];
		$hooks = [
			10 => $this->webhook(10, false, 5),
			20 => $this->webhook(20, true, 5),
			30 => $this->webhook(30, true, 5),
			40 => $this->webhook(40, true, 5),
		];
		$logMapper = $this->createMock(WebhookLogMapper::class);
		$logMapper->method('findFailedForRetry')->willReturn($logs);
		$hookMapper = $this->createMock(WebhookMapper::class);
		$hookMapper->method('find')->willReturnCallback(
			function (int $id) use ($hooks) {
				if (isset($hooks[$id]) === false) {
					throw new \RuntimeException('gone ' . $id);
				}
				return $hooks[$id];
			}
		);
		$delivered = [];
		$service = $this->createMock(WebhookService::class);
		$service->method('deliverWebhook')->willReturnCallback(
			function (WebhookDelivery $delivery) use (&$delivered) {
				$delivered[] = [$delivery->webhook->getId(), $delivery->event->name, $delivery->event->payload, $delivery->attempt];
				return $delivery->webhook->getId() === 30;
			}
		);
		$job = new WebhookRetryJob(
			$this->createMock(ITimeFactory::class),
			new WebhookDeliveryStores($hookMapper, $logMapper),
			$service,
			$logger
		);

		$this->runJob($job);

		$file = (new \ReflectionClass(WebhookRetryJob::class))->getFileName();
		$this->assertSame([[30, 'Evt3', ['n' => 3], 3], [40, 'Evt4', ['n' => 4], 4]], $delivered);
		$this->assertSame(
			[
				['debug', '[WebhookRetryJob] Checking for webhook retries', ['file' => $file, 'timestamp' => 'ts']],
				['info', '[WebhookRetryJob] Processing webhook retries', ['file' => $file, 'count' => 5]],
				['debug', '[WebhookRetryJob] Skipping retry for disabled webhook', ['file' => $file, 'webhook_id' => 10, 'log_id' => 1]],
				['warning', '[WebhookRetryJob] Webhook retry limit exceeded', ['file' => $file, 'webhook_id' => 20, 'log_id' => 2, 'attempt' => 5, 'max_retries' => 5]],
				['info', '[WebhookRetryJob] Retrying webhook delivery', ['file' => $file, 'webhook_id' => 30, 'log_id' => 3, 'attempt' => 3]],
				['info', '[WebhookRetryJob] Webhook retry succeeded', ['file' => $file, 'webhook_id' => 30, 'log_id' => 3]],
				['info', '[WebhookRetryJob] Retrying webhook delivery', ['file' => $file, 'webhook_id' => 40, 'log_id' => 4, 'attempt' => 4]],
				['warning', '[WebhookRetryJob] Webhook retry failed', ['file' => $file, 'webhook_id' => 40, 'log_id' => 4, 'attempt' => 4]],
				['error', '[WebhookRetryJob] Error processing webhook retry', ['file' => $file, 'log_id' => 5, 'error' => 'gone 50']],
			],
			$logger->trail
		);
	}
}
