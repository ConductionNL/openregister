<?php

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\WebhookRetryJob;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\WebhookService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WebhookRetryJobTest extends TestCase {
	private WebhookRetryJob $job;
	private WebhookMapper&MockObject $webhookMapper;
	private WebhookLogMapper&MockObject $webhookLogMapper;
	private WebhookService&MockObject $webhookService;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$timeFactory = $this->createMock(ITimeFactory::class);
		$this->webhookMapper = $this->createMock(WebhookMapper::class);
		$this->webhookLogMapper = $this->createMock(WebhookLogMapper::class);
		$this->webhookService = $this->createMock(WebhookService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->job = new WebhookRetryJob(
			$timeFactory,
			$this->webhookMapper,
			$this->webhookLogMapper,
			$this->webhookService,
			$this->logger,
		);
	}

	private function runJob($argument = null): void {
		$reflection = new \ReflectionClass($this->job);
		$method = $reflection->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($this->job, $argument);
	}

	/**
	 * Create a WebhookLog entity with values set via setters.
	 *
	 * A real WebhookLog, deliberately. This helper used to build a
	 * TestWebhookLog subclass carrying an invented `webhookId` property,
	 * because the job called getWebhookId() and the entity only has
	 * `webhook`. The subclass made the suite green while every real retry
	 * threw "webhookId is not a valid attribute" out of Entity::__call.
	 * The job now calls getWebhook(), so the crutch is gone and this test
	 * exercises the same call surface production does.
	 */
	private function createWebhookLogEntity(int $id, int $webhookId, int $attempt, string $eventClass = 'TestEvent', array $payload = ['key' => 'value']): WebhookLog {
		$log = new WebhookLog();
		$log->setId($id);
		$log->setWebhook($webhookId);
		$log->setAttempt($attempt);
		$log->setEventClass($eventClass);
		$log->setPayloadArray($payload);

		return $log;
	}

	private function createWebhookEntity(int $id, bool $enabled, int $maxRetries = 5): Webhook {
		$webhook = new Webhook();
		$webhook->setId($id);
		$webhook->setEnabled($enabled);
		$webhook->setMaxRetries($maxRetries);
		return $webhook;
	}

	public function testConstructorSetsInterval(): void {
		$reflection = new \ReflectionClass($this->job);
		$property = $reflection->getProperty('interval');
		$property->setAccessible(true);

		$this->assertEquals(300, $property->getValue($this->job));
	}

	public function testRunWithNoFailedLogs(): void {
		$this->webhookLogMapper->expects($this->once())
			->method('findFailedForRetry')
			->willReturn([]);

		$this->webhookMapper->expects($this->never())->method('find');
		$this->webhookService->expects($this->never())->method('deliverWebhook');

		$this->logger->expects($this->exactly(2))->method('debug');

		$this->runJob(null);
	}

	public function testRunWithDisabledWebhook(): void {
		$log = $this->createWebhookLogEntity(1, 10, 2);
		$webhook = $this->createWebhookEntity(10, false);

		$this->webhookLogMapper->method('findFailedForRetry')->willReturn([$log]);
		$this->webhookMapper->method('find')->with(10)->willReturn($webhook);

		$this->webhookService->expects($this->never())->method('deliverWebhook');
		$this->logger->expects($this->atLeastOnce())->method('debug');

		$this->runJob(null);
	}

	public function testRunWithMaxRetriesExceeded(): void {
		$log = $this->createWebhookLogEntity(1, 10, 5);
		$webhook = $this->createWebhookEntity(10, true, 5);

		$this->webhookLogMapper->method('findFailedForRetry')->willReturn([$log]);
		$this->webhookMapper->method('find')->with(10)->willReturn($webhook);

		$this->webhookService->expects($this->never())->method('deliverWebhook');
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->runJob(null);
	}

	public function testRunWithSuccessfulRetry(): void {
		$log = $this->createWebhookLogEntity(1, 10, 2, 'SomeEvent');
		$webhook = $this->createWebhookEntity(10, true, 5);

		$this->webhookLogMapper->method('findFailedForRetry')->willReturn([$log]);
		$this->webhookMapper->method('find')->with(10)->willReturn($webhook);

		$this->webhookService->expects($this->once())
			->method('deliverWebhook')
			->with($webhook, 'SomeEvent', $this->isType('array'), 3)
			->willReturn(true);

		$this->logger->expects($this->atLeastOnce())->method('info');

		$this->runJob(null);
	}

	public function testRunWithFailedRetry(): void {
		$log = $this->createWebhookLogEntity(1, 10, 1);
		$webhook = $this->createWebhookEntity(10, true, 5);

		$this->webhookLogMapper->method('findFailedForRetry')->willReturn([$log]);
		$this->webhookMapper->method('find')->with(10)->willReturn($webhook);

		$this->webhookService->expects($this->once())
			->method('deliverWebhook')
			->willReturn(false);

		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->runJob(null);
	}

	public function testRunWithExceptionDuringRetry(): void {
		$log = $this->createWebhookLogEntity(1, 10, 1);

		$this->webhookLogMapper->method('findFailedForRetry')->willReturn([$log]);
		$this->webhookMapper->method('find')
			->willThrowException(new \Exception('Webhook not found'));

		$this->webhookService->expects($this->never())->method('deliverWebhook');
		$this->logger->expects($this->atLeastOnce())->method('error');

		$this->runJob(null);
	}

	public function testRunWithMultipleLogs(): void {
		$log1 = $this->createWebhookLogEntity(1, 10, 1);
		$log2 = $this->createWebhookLogEntity(2, 20, 3);
		$log3 = $this->createWebhookLogEntity(3, 30, 0);

		$webhook1 = $this->createWebhookEntity(10, true, 5);
		$webhook2 = $this->createWebhookEntity(20, false);
		$webhook3 = $this->createWebhookEntity(30, true, 3);

		$this->webhookLogMapper->method('findFailedForRetry')->willReturn([$log1, $log2, $log3]);

		$this->webhookMapper->method('find')
			->willReturnCallback(function (int $id) use ($webhook1, $webhook2, $webhook3) {
				return match ($id) {
					10 => $webhook1,
					20 => $webhook2,
					30 => $webhook3,
					default => throw new \Exception('Unknown'),
				};
			});

		// Only log1 and log3 should attempt delivery (log2's webhook is disabled)
		$this->webhookService->expects($this->exactly(2))
			->method('deliverWebhook')
			->willReturn(true);

		$this->runJob(null);
	}

	public function testRunAttemptEqualsMaxRetriesIsSkipped(): void {
		$log = $this->createWebhookLogEntity(1, 10, 3);
		$webhook = $this->createWebhookEntity(10, true, 3);

		$this->webhookLogMapper->method('findFailedForRetry')->willReturn([$log]);
		$this->webhookMapper->method('find')->willReturn($webhook);

		$this->webhookService->expects($this->never())->method('deliverWebhook');

		$this->runJob(null);
	}

	public function testRunAttemptBelowMaxRetriesIsProcessed(): void {
		$log = $this->createWebhookLogEntity(1, 10, 2);
		$webhook = $this->createWebhookEntity(10, true, 3);

		$this->webhookLogMapper->method('findFailedForRetry')->willReturn([$log]);
		$this->webhookMapper->method('find')->willReturn($webhook);

		$this->webhookService->expects($this->once())
			->method('deliverWebhook')
			->with($webhook, 'TestEvent', $this->isType('array'), 3)
			->willReturn(true);

		$this->runJob(null);
	}

	public function testRunExceptionDoesNotStopOtherLogs(): void {
		$log1 = $this->createWebhookLogEntity(1, 10, 1);
		$log2 = $this->createWebhookLogEntity(2, 20, 1);

		$webhook2 = $this->createWebhookEntity(20, true, 5);

		$this->webhookLogMapper->method('findFailedForRetry')->willReturn([$log1, $log2]);

		$callCount = 0;
		$this->webhookMapper->method('find')
			->willReturnCallback(function (int $id) use ($webhook2, &$callCount) {
				$callCount++;
				if ($id === 10) {
					throw new \Exception('DB error');
				}
				return $webhook2;
			});

		// Second log should still be processed
		$this->webhookService->expects($this->once())
			->method('deliverWebhook')
			->willReturn(true);

		$this->runJob(null);
		$this->assertEquals(2, $callCount);
	}

	public function testRunPassesCorrectAttemptNumber(): void {
		$log = $this->createWebhookLogEntity(1, 10, 4);
		$webhook = $this->createWebhookEntity(10, true, 10);

		$this->webhookLogMapper->method('findFailedForRetry')->willReturn([$log]);
		$this->webhookMapper->method('find')->willReturn($webhook);

		$this->webhookService->expects($this->once())
			->method('deliverWebhook')
			->with($webhook, 'TestEvent', $this->isType('array'), 5)
			->willReturn(true);

		$this->runJob(null);
	}

	public function testRunAttemptAboveMaxRetriesIsSkipped(): void {
		$log = $this->createWebhookLogEntity(1, 10, 7);
		$webhook = $this->createWebhookEntity(10, true, 5);

		$this->webhookLogMapper->method('findFailedForRetry')->willReturn([$log]);
		$this->webhookMapper->method('find')->willReturn($webhook);

		$this->webhookService->expects($this->never())->method('deliverWebhook');
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->runJob(null);
	}

	public function testRunDeliveryExceptionIsCaught(): void {
		$log = $this->createWebhookLogEntity(1, 10, 1);
		$webhook = $this->createWebhookEntity(10, true, 5);

		$this->webhookLogMapper->method('findFailedForRetry')->willReturn([$log]);
		$this->webhookMapper->method('find')->willReturn($webhook);

		$this->webhookService->method('deliverWebhook')
			->willThrowException(new \Exception('Network error'));

		$this->logger->expects($this->atLeastOnce())->method('error');

		$this->runJob(null);
	}
}

