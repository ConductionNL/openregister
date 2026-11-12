<?php

/**
 * Async webhook dispatch tests.
 *
 * Proves dispatchEvent() enqueues delivery via the background job list
 * instead of calling deliverWebhook() synchronously, and that it
 * short-circuits without any enqueue/log work when no webhook matches the
 * event (async-webhook-delivery).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\BackgroundJob\WebhookDeliveryJob;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\WebhookDispatchServices;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Service\WebhookStores;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests proving dispatchEvent() delivers asynchronously via IJobList.
 */
class WebhookAsyncDispatchTest extends TestCase {
	private WebhookMapper&MockObject $webhookMapper;
	private WebhookLogMapper&MockObject $webhookLogMapper;
	private MappingService&MockObject $mappingService;
	private MappingMapper&MockObject $mappingMapper;
	private IJobList&MockObject $jobList;
	private LoggerInterface&MockObject $logger;
	private WebhookService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->webhookMapper = $this->createMock(WebhookMapper::class);
		$this->webhookLogMapper = $this->createMock(WebhookLogMapper::class);
		$this->mappingService = $this->createMock(MappingService::class);
		$this->mappingMapper = $this->createMock(MappingMapper::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new WebhookService(
			stores: new WebhookStores(
				webhookMapper: $this->webhookMapper,
				webhookLogMapper: $this->webhookLogMapper,
				mappingMapper: $this->mappingMapper,
				mappingService: $this->mappingService
			),
			dispatch: new WebhookDispatchServices(
				jobList: $this->jobList
			),
			logger: $this->logger
		);
	}

	/**
	 * No matching webhooks -> no job enqueued, no log written, no error.
	 */
	public function testNoMatchingWebhooksEnqueuesNoJobAndDoesNoWork(): void {
		$event = $this->createMock(Event::class);

		$this->webhookMapper->method('findForEvent')->willReturn([]);

		$this->jobList->expects($this->never())->method('add');
		$this->webhookLogMapper->expects($this->never())->method('insert');

		$this->service->dispatchEvent('OCA\\OpenRegister\\Event\\ObjectCreatedEvent', ['test' => 'data']);
	}

	/**
	 * findForEvent() throwing (e.g. table not migrated yet) must also result
	 * in zero enqueued jobs — the async short-circuit and the error
	 * short-circuit are independent paths that both must avoid work.
	 */
	public function testExceptionFromFindForEventEnqueuesNoJob(): void {
		$event = $this->createMock(Event::class);

		$this->webhookMapper->method('findForEvent')
			->willThrowException(new \Exception('table missing'));

		$this->jobList->expects($this->never())->method('add');

		$this->service->dispatchEvent('SomeEvent', ['test' => 'data']);
	}

	/**
	 * A single matching webhook results in exactly one job enqueued with the
	 * expected job class and payload shape, and never a synchronous log
	 * insert (which only deliverWebhook()/the background job perform).
	 */
	public function testSingleMatchingWebhookEnqueuesOneJob(): void {
		$event = $this->createMock(Event::class);

		$webhook = new Webhook();
		$webhook->setId(9);

		$this->webhookMapper->method('findForEvent')->willReturn([$webhook]);

		$this->jobList->expects($this->once())
			->method('add')
			->with(
				$this->equalTo(WebhookDeliveryJob::class),
				$this->callback(function (array $arg) {
					return $arg['webhook_id'] === 9
						&& $arg['event_name'] === 'OCA\\OpenRegister\\Event\\ObjectUpdatedEvent'
						&& $arg['payload'] === ['test' => 'data']
						&& $arg['attempt'] === 1;
				})
			);

		// Synchronous delivery must not happen: no log write from this call.
		$this->webhookLogMapper->expects($this->never())->method('insert');

		$this->service->dispatchEvent(
			'OCA\\OpenRegister\\Event\\ObjectUpdatedEvent',
			['test' => 'data']
		);
	}

	/**
	 * Multiple matching webhooks each get their own enqueued job (fan-out),
	 * proving delivery is per-webhook rather than a single shared job.
	 */
	public function testMultipleMatchingWebhooksEachEnqueueTheirOwnJob(): void {
		$event = $this->createMock(Event::class);

		$webhookA = new Webhook();
		$webhookA->setId(1);
		$webhookB = new Webhook();
		$webhookB->setId(2);
		$webhookC = new Webhook();
		$webhookC->setId(3);

		$this->webhookMapper->method('findForEvent')
			->willReturn([$webhookA, $webhookB, $webhookC]);

		$enqueuedWebhookIds = [];
		$this->jobList->expects($this->exactly(3))
			->method('add')
			->willReturnCallback(function (string $job, array $arg) use (&$enqueuedWebhookIds) {
				$this->assertSame(WebhookDeliveryJob::class, $job);
				$enqueuedWebhookIds[] = $arg['webhook_id'];
			});

		$this->service->dispatchEvent('SomeEvent', ['k' => 'v']);

		$this->assertSame([1, 2, 3], $enqueuedWebhookIds);
	}

	/**
	 * dispatchEvent() must never call deliverWebhook() itself for the first
	 * attempt; delivery is fully delegated to the background job. We assert
	 * this using a partial mock of WebhookService that expects
	 * deliverWebhook() is never invoked directly by dispatchEvent().
	 */
	public function testDispatchEventNeverCallsDeliverWebhookSynchronously(): void {
		$event = $this->createMock(Event::class);

		$webhook = new Webhook();
		$webhook->setId(5);

		$this->webhookMapper->method('findForEvent')->willReturn([$webhook]);

		$partialService = $this->getMockBuilder(WebhookService::class)
			->setConstructorArgs(
				[
					new WebhookStores($this->webhookMapper, $this->webhookLogMapper, $this->mappingMapper, $this->mappingService),
					new WebhookDispatchServices($this->jobList, null),
					$this->logger,
				]
			)
			->onlyMethods(['deliverWebhook'])
			->getMock();

		$partialService->expects($this->never())->method('deliverWebhook');

		$partialService->dispatchEvent('SomeEvent', ['test' => 'data']);
	}
}
