<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\WebhookDeliveryJob;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\WebhookDelivery;
use OCA\OpenRegister\Service\WebhookService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Characterisation test: pins the exact log trail (level, message and context,
 * without the source line and trace) and the delivery made by
 * WebhookDeliveryJob::run on every branch.
 */
class WebhookDeliveryJobLogTrailTest extends TestCase {

	/** @var array<int, array{0: int, 1: string, 2: array, 3: int}> */
	private array $delivered = [];

	private function recordingLogger(): AbstractLogger {
		return new class extends AbstractLogger {
			/** @var array<int, array{0: string, 1: string, 2: array}> */
			public array $trail = [];

			public function log($level, $message, array $context = []): void {
				unset($context['line']);
				if (isset($context['trace']) === true) {
					$context['trace'] = 'trace';
				}
				$this->trail[] = [$level, (string) $message, $context];
			}
		};
	}

	/**
	 * Run the job with a mapper that knows webhook 7 and a service returning $result.
	 *
	 * @param mixed          $argument The job argument
	 * @param bool           $result   What deliverWebhook returns
	 * @param AbstractLogger $logger   The recording logger
	 */
	private function runJob(mixed $argument, bool $result, AbstractLogger $logger): void {
		$webhook = new Webhook();
		$webhook->setId(7);
		$webhook->setName('Hook seven');
		$mapper = $this->createMock(WebhookMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (int $id) use ($webhook) {
				if ($id !== 7) {
					throw new \RuntimeException('no webhook ' . $id);
				}
				return $webhook;
			}
		);
		$service = $this->createMock(WebhookService::class);
		$service->method('deliverWebhook')->willReturnCallback(
			function (WebhookDelivery $delivery) use ($result) {
				$this->delivered[] = [$delivery->webhook->getId(), $delivery->event->name, $delivery->event->payload, $delivery->attempt];
				return $result;
			}
		);
		$job = new WebhookDeliveryJob($this->createMock(ITimeFactory::class), $mapper, $service, $logger);
		$method = new \ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, $argument);
	}

	private function file(): string {
		return (string) (new \ReflectionClass(WebhookDeliveryJob::class))->getFileName();
	}

	public function testInvalidArgumentsLogTheArgument(): void {
		$logger = $this->recordingLogger();
		$this->runJob(['webhook_id' => 7], true, $logger);

		$this->assertSame([], $this->delivered);
		$this->assertSame(
			[['error', '[WebhookDeliveryJob] WebhookDeliveryJob called with invalid arguments', ['file' => $this->file(), 'argument' => ['webhook_id' => 7]]]],
			$logger->trail
		);
	}

	public function testSuccessfulDeliveryWithDefaults(): void {
		$logger = $this->recordingLogger();
		$this->runJob(['webhook_id' => 7, 'event_name' => 'E'], true, $logger);

		$this->assertSame([[7, 'E', [], 1]], $this->delivered);
		$context = ['file' => $this->file(), 'webhook_id' => 7, 'webhook_name' => 'Hook seven', 'event' => 'E', 'attempt' => 1];
		$this->assertSame(
			[
				['info', '[WebhookDeliveryJob] Executing webhook delivery job', $context],
				['info', '[WebhookDeliveryJob] Webhook delivery job completed successfully', $context],
			],
			$logger->trail
		);
	}

	public function testFailedDelivery(): void {
		$logger = $this->recordingLogger();
		$this->runJob(['webhook_id' => 7, 'event_name' => 'E', 'payload' => ['a' => 1], 'attempt' => 3], false, $logger);

		$this->assertSame([[7, 'E', ['a' => 1], 3]], $this->delivered);
		$context = ['file' => $this->file(), 'webhook_id' => 7, 'webhook_name' => 'Hook seven', 'event' => 'E', 'attempt' => 3];
		$this->assertSame(
			[
				['info', '[WebhookDeliveryJob] Executing webhook delivery job', $context],
				['warning', '[WebhookDeliveryJob] Webhook delivery job failed', $context],
			],
			$logger->trail
		);
	}

	public function testExceptionIsLoggedWithArguments(): void {
		$logger = $this->recordingLogger();
		$this->runJob(['webhook_id' => 9, 'event_name' => 'E', 'attempt' => 2], true, $logger);

		$this->assertSame([], $this->delivered);
		$this->assertSame(
			[
				[
					'error',
					'[WebhookDeliveryJob] Webhook delivery job encountered an exception',
					['file' => $this->file(), 'webhook_id' => 9, 'event' => 'E', 'attempt' => 2, 'error' => 'no webhook 9', 'trace' => 'trace'],
				],
			],
			$logger->trail
		);
	}
}
