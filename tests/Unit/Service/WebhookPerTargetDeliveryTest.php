<?php

/**
 * Per-target webhook delivery tests.
 *
 * A webhook whose configuration sets `deliverPerTarget: true` holds the event,
 * method, secret and retry policy, and every delivery names its own URL through
 * WebhookService::triggerWebhookForEvent(). These tests prove the URL travels from
 * the call through the job and the request to the log, that the outbound address
 * checks still apply to it, and that ordinary and owned webhooks cannot be retargeted.
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

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use InvalidArgumentException;
use OCA\OpenRegister\BackgroundJob\Support\WebhookDeliveryStores;
use OCA\OpenRegister\BackgroundJob\WebhookDeliveryJob;
use OCA\OpenRegister\BackgroundJob\WebhookRetryJob;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\WebhookDelivery;
use OCA\OpenRegister\Service\WebhookDispatchServices;
use OCA\OpenRegister\Service\WebhookEvent;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Service\WebhookStores;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\WebhookService
 * @covers \OCA\OpenRegister\Service\WebhookDelivery
 * @covers \OCA\OpenRegister\Service\WebhookDeliveryRecorder
 * @covers \OCA\OpenRegister\BackgroundJob\WebhookDeliveryJob
 * @covers \OCA\OpenRegister\BackgroundJob\WebhookRetryJob
 * @uses   \OCA\OpenRegister\Db\Webhook
 * @uses   \OCA\OpenRegister\Db\WebhookLog
 * @uses   \OCA\OpenRegister\BackgroundJob\Support\WebhookDeliveryStores
 * @uses   \OCA\OpenRegister\Service\Security\OutboundAddressGuard
 * @uses   \OCA\OpenRegister\Service\WebhookDeliveryAttempt
 * @uses   \OCA\OpenRegister\Service\WebhookDispatchServices
 * @uses   \OCA\OpenRegister\Service\WebhookEvent
 * @uses   \OCA\OpenRegister\Service\WebhookRequestOptions
 * @uses   \OCA\OpenRegister\Service\WebhookStores
 *
 * @spec openspec/changes/webhook-per-delivery-target/specs/webhook-payload-mapping/spec.md
 */
class WebhookPerTargetDeliveryTest extends TestCase {
	private const EVENT = 'opencatalogi.federation.broadcast';

	private WebhookMapper&MockObject $webhookMapper;
	private WebhookLogMapper&MockObject $webhookLogMapper;
	private IJobList&MockObject $jobList;
	private WebhookService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->webhookMapper = $this->createMock(WebhookMapper::class);
		$this->webhookLogMapper = $this->createMock(WebhookLogMapper::class);
		$this->jobList = $this->createMock(IJobList::class);

		$this->service = new WebhookService(
			stores: new WebhookStores(
				webhookMapper: $this->webhookMapper,
				webhookLogMapper: $this->webhookLogMapper,
				mappingMapper: $this->createMock(MappingMapper::class),
				mappingService: $this->createMock(MappingService::class)
			),
			dispatch: new WebhookDispatchServices(jobList: $this->jobList),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A webhook subscribed to the test event.
	 *
	 * @param int         $id        Webhook id.
	 * @param bool        $perTarget Whether it delivers per target.
	 * @param string|null $owner     Owning user, or null for an administrator's webhook.
	 *
	 * @return Webhook
	 */
	private function webhook(int $id, bool $perTarget, ?string $owner = null): Webhook {
		$webhook = new Webhook();
		$webhook->setId($id);
		$webhook->setName('hook-' . $id);
		$webhook->setUrl('https://93.184.216.34/placeholder');
		$webhook->setMethod('POST');
		$webhook->setEnabled(true);
		$webhook->setMaxRetries(5);
		$webhook->setEvents(json_encode([self::EVENT]));
		if ($perTarget === true) {
			$webhook->setConfigurationArray([WebhookDelivery::PER_TARGET_KEY => true]);
		}

		if ($owner !== null) {
			$webhook->setOwner($owner);
		}

		return $webhook;
	}//end webhook()

	/**
	 * Swap the service's HTTP client for one that records the request.
	 *
	 * @param array<string, mixed> $seen Filled with the method and URI of each request.
	 *
	 * @return void
	 */
	private function recordRequests(array &$seen): void {
		$client = $this->createMock(GuzzleClient::class);
		$client->method('request')->willReturnCallback(
			function (string $method, $uri) use (&$seen): GuzzleResponse {
				$seen[] = ['method' => $method, 'uri' => (string)$uri];
				return new GuzzleResponse(200, [], 'ok');
			}
		);
		$property = (new \ReflectionClass($this->service))->getProperty('client');
		$property->setAccessible(true);
		$property->setValue($this->service, $client);
	}//end recordRequests()

	public function testTriggerEnqueuesOneDeliveryToTheNamedUrlThroughThePerTargetWebhookOnly(): void {
		$this->webhookMapper->method('findForEvent')->with(self::EVENT)->willReturn(
			[$this->webhook(1, false), $this->webhook(2, true), $this->webhook(3, true, 'alice')]
		);

		$queued = [];
		$this->jobList->expects($this->once())->method('add')->willReturnCallback(
			function (string $job, array $argument) use (&$queued): void {
				$queued[] = [$job, $argument];
			}
		);

		$count = $this->service->triggerWebhookForEvent(
			eventName: self::EVENT,
			payload: ['directory' => 'https://a.example/directory'],
			url: ' https://peer.example/api/directory '
		);

		$this->assertSame(1, $count);
		$this->assertSame(
			[
				[
					WebhookDeliveryJob::class,
					[
						'webhook_id' => 2,
						'event_name' => self::EVENT,
						'payload' => ['directory' => 'https://a.example/directory'],
						'attempt' => 1,
						'target_url' => 'https://peer.example/api/directory',
					],
				],
			],
			$queued
		);
	}//end testTriggerEnqueuesOneDeliveryToTheNamedUrlThroughThePerTargetWebhookOnly()

	public function testTriggerWithoutAPerTargetWebhookEnqueuesNothing(): void {
		$this->webhookMapper->method('findForEvent')->willReturn([$this->webhook(1, false)]);
		$this->jobList->expects($this->never())->method('add');

		$this->assertSame(0, $this->service->triggerWebhookForEvent(eventName: self::EVENT, payload: [], url: 'https://peer.example/x'));
	}//end testTriggerWithoutAPerTargetWebhookEnqueuesNothing()

	public function testTriggerWithAnEmptyUrlIsRefused(): void {
		$this->jobList->expects($this->never())->method('add');
		$this->expectException(InvalidArgumentException::class);

		$this->service->triggerWebhookForEvent(eventName: self::EVENT, payload: [], url: '  ');
	}//end testTriggerWithAnEmptyUrlIsRefused()

	public function testAnEnqueueRefusalIsLoggedAndCountsNothing(): void {
		$this->webhookMapper->method('findForEvent')->willReturn([$this->webhook(2, true), $this->webhook(4, true)]);
		$this->jobList->expects($this->exactly(2))->method('add')->willReturnOnConsecutiveCalls(
			$this->throwException(new \RuntimeException('argument too long')),
			null
		);

		$this->assertSame(1, $this->service->triggerWebhookForEvent(eventName: self::EVENT, payload: [], url: 'https://peer.example/x'));
	}//end testAnEnqueueRefusalIsLoggedAndCountsNothing()

	public function testAnOrdinaryDispatchSkipsAPerTargetWebhook(): void {
		$this->webhookMapper->method('findForEvent')->willReturn([$this->webhook(1, false), $this->webhook(2, true)]);

		$ids = [];
		$this->jobList->expects($this->once())->method('add')->willReturnCallback(
			function (string $job, array $argument) use (&$ids): void {
				$ids[] = $argument['webhook_id'];
				$this->assertArrayNotHasKey('target_url', $argument);
			}
		);

		$this->service->dispatchEvent(self::EVENT, ['k' => 'v']);

		$this->assertSame([1], $ids);
	}//end testAnOrdinaryDispatchSkipsAPerTargetWebhook()

	public function testAPerTargetDeliveryIsSentToAndLoggedWithItsOwnUrl(): void {
		$seen = [];
		$this->recordRequests(seen: $seen);
		$logged = [];
		$this->webhookLogMapper->method('insert')->willReturnCallback(
			function (WebhookLog $log) use (&$logged): WebhookLog {
				$logged[] = $log->getUrl();
				return $log;
			}
		);

		$delivered = $this->service->deliverWebhook(
			new WebhookDelivery(
				webhook: $this->webhook(2, true),
				event: new WebhookEvent(name: self::EVENT, payload: ['directory' => 'd']),
				targetUrl: 'https://93.184.216.35/api/directory'
			)
		);

		$this->assertTrue($delivered);
		$this->assertSame([['method' => 'POST', 'uri' => 'https://93.184.216.35/api/directory']], $seen);
		$this->assertSame(['https://93.184.216.35/api/directory'], $logged);
	}//end testAPerTargetDeliveryIsSentToAndLoggedWithItsOwnUrl()

	public function testAPerTargetDeliveryWithoutAUrlSendsNothing(): void {
		$seen = [];
		$this->recordRequests(seen: $seen);
		$this->webhookLogMapper->expects($this->never())->method('insert');

		$delivered = $this->service->deliverWebhook(
			new WebhookDelivery(webhook: $this->webhook(2, true), event: new WebhookEvent(name: self::EVENT, payload: []))
		);

		$this->assertFalse($delivered);
		$this->assertSame([], $seen);
	}//end testAPerTargetDeliveryWithoutAUrlSendsNothing()

	public function testAPrivateTargetUrlIsRefusedLikeAFixedOne(): void {
		$seen = [];
		$this->recordRequests(seen: $seen);
		$this->webhookLogMapper->method('insert')->willReturnArgument(0);

		$delivered = $this->service->deliverWebhook(
			new WebhookDelivery(
				webhook: $this->webhook(2, true),
				event: new WebhookEvent(name: self::EVENT, payload: []),
				targetUrl: 'http://169.254.169.254/latest/meta-data'
			)
		);

		$this->assertFalse($delivered);
		$this->assertSame([], $seen);
	}//end testAPrivateTargetUrlIsRefusedLikeAFixedOne()

	public function testAnOrdinaryWebhookIgnoresATargetUrl(): void {
		$seen = [];
		$this->recordRequests(seen: $seen);
		$this->webhookLogMapper->method('insert')->willReturnArgument(0);

		$this->service->deliverWebhook(
			new WebhookDelivery(
				webhook: $this->webhook(1, false),
				event: new WebhookEvent(name: self::EVENT, payload: []),
				targetUrl: 'https://93.184.216.35/elsewhere'
			)
		);

		$this->assertSame([['method' => 'POST', 'uri' => 'https://93.184.216.34/placeholder']], $seen);
	}//end testAnOrdinaryWebhookIgnoresATargetUrl()

	public function testAnOwnedWebhookNeverDeliversPerTarget(): void {
		$owned = $this->webhook(3, true, 'alice');

		$this->assertFalse(WebhookDelivery::deliversPerTarget(webhook: $owned));
		$this->assertSame(
			'https://93.184.216.34/placeholder',
			(new WebhookDelivery(webhook: $owned, event: new WebhookEvent(name: self::EVENT, payload: []), targetUrl: 'https://x.example/'))->url()
		);
	}//end testAnOwnedWebhookNeverDeliversPerTarget()

	public function testARetryGoesToTheLoggedUrlOnlyForAPerTargetWebhook(): void {
		$this->assertSame('https://peer.example/x', WebhookDelivery::retryTarget(webhook: $this->webhook(2, true), loggedUrl: 'https://peer.example/x'));
		$this->assertNull(WebhookDelivery::retryTarget(webhook: $this->webhook(2, true), loggedUrl: ''));
		$this->assertNull(WebhookDelivery::retryTarget(webhook: $this->webhook(1, false), loggedUrl: 'https://old.example/x'));
	}//end testARetryGoesToTheLoggedUrlOnlyForAPerTargetWebhook()

	public function testTheDeliveryJobHandsTheQueuedUrlToTheService(): void {
		$service = $this->createMock(WebhookService::class);
		$this->webhookMapper->method('find')->with(2)->willReturn($this->webhook(2, true));
		$service->expects($this->once())->method('deliverWebhook')->with(
			$this->callback(
				fn (WebhookDelivery $delivery): bool => $delivery->targetUrl === 'https://peer.example/x'
					&& $delivery->attempt === 1
					&& $delivery->event->name === self::EVENT
			)
		)->willReturn(true);

		$job = new WebhookDeliveryJob($this->createMock(ITimeFactory::class), $this->webhookMapper, $service, $this->createMock(LoggerInterface::class));
		$run = new \ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, ['webhook_id' => 2, 'event_name' => self::EVENT, 'payload' => [], 'attempt' => 1, 'target_url' => 'https://peer.example/x']);
	}//end testTheDeliveryJobHandsTheQueuedUrlToTheService()

	public function testTheRetryJobRetriesAPerTargetDeliveryToItsLoggedUrl(): void {
		$service = $this->createMock(WebhookService::class);
		$log = new WebhookLog();
		$log->setWebhook(2);
		$log->setEventClass(self::EVENT);
		$log->setUrl('https://peer.example/x');
		$log->setAttempt(1);
		$this->webhookLogMapper->method('findFailedForRetry')->willReturn([$log]);
		$this->webhookMapper->method('find')->with(2)->willReturn($this->webhook(2, true));
		$service->expects($this->once())->method('deliverWebhook')->with(
			$this->callback(
				fn (WebhookDelivery $delivery): bool => $delivery->targetUrl === 'https://peer.example/x' && $delivery->attempt === 2
			)
		)->willReturn(true);

		$job = new WebhookRetryJob(
			$this->createMock(ITimeFactory::class),
			new WebhookDeliveryStores($this->webhookMapper, $this->webhookLogMapper),
			$service,
			$this->createMock(LoggerInterface::class)
		);
		$run = new \ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);
	}//end testTheRetryJobRetriesAPerTargetDeliveryToItsLoggedUrl()
}//end class
