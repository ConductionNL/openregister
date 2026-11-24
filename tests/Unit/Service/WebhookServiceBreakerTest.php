<?php

/**
 * Webhook delivery goes through the receiver host's breaker: failures open it,
 * an open breaker defers the delivery to the retry job without sending it or
 * counting an attempt, and an interception delivery is skipped at once.
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
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\Resilience\BreakerRegistry;
use OCA\OpenRegister\Service\Resilience\BreakerThresholds;
use OCA\OpenRegister\Service\Resilience\CircuitBreaker;
use OCA\OpenRegister\Service\Resilience\DependencyBreaker;
use OCA\OpenRegister\Service\WebhookDelivery;
use OCA\OpenRegister\Service\WebhookDispatchServices;
use OCA\OpenRegister\Service\WebhookEvent;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Service\WebhookStores;
use OCA\OpenRegister\Tests\Unit\Service\Resilience\FakeMemcache;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

require_once __DIR__ . '/Resilience/FakeMemcache.php';

/**
 * @covers \OCA\OpenRegister\Service\WebhookService
 * @uses   \OCA\OpenRegister\Service\WebhookDeliveryRecorder
 * @uses   \OCA\OpenRegister\Service\WebhookDeliveryAttempt
 * @uses   \OCA\OpenRegister\Service\WebhookDelivery
 * @uses   \OCA\OpenRegister\Service\WebhookEvent
 * @uses   \OCA\OpenRegister\Service\WebhookStores
 * @uses   \OCA\OpenRegister\Service\WebhookDispatchServices
 * @uses   \OCA\OpenRegister\Service\WebhookRequestOptions
 * @uses   \OCA\OpenRegister\Service\Security\OutboundAddressGuard
 * @uses   \OCA\OpenRegister\Db\Webhook
 * @uses   \OCA\OpenRegister\Db\WebhookLog
 * @uses   \OCA\OpenRegister\Service\Resilience\BreakerRegistry
 * @uses   \OCA\OpenRegister\Service\Resilience\BreakerThresholds
 * @uses   \OCA\OpenRegister\Service\Resilience\CircuitBreaker
 * @uses   \OCA\OpenRegister\Service\Resilience\DependencyBreaker
 * @uses   \OCA\OpenRegister\Service\Resilience\DependencyUnavailableException
 */
class WebhookServiceBreakerTest extends TestCase {

	private const KEY = 'webhook:93.184.216.34';

	private int $now = 1_000_000;

	private CircuitBreaker $breaker;

	/** @var list<WebhookLog> The log rows inserted. */
	private array $logs = [];

	/** @var list<bool> The statistics updates. */
	private array $statistics = [];

	private int $requests = 0;

	/** @var int The status the receiver answers; 0 is a connection error. */
	private int $answer = 200;

	protected function setUp(): void {
		parent::setUp();
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$this->breaker = new CircuitBreaker(cache: new FakeMemcache(), thresholds: new BreakerThresholds(), clock: $clock, logger: new NullLogger(), shared: true);
	}

	private function service(): WebhookService {
		$webhookMapper = $this->createMock(WebhookMapper::class);
		$webhookMapper->method('updateStatistics')->willReturnCallback(function (Webhook $webhook, bool $success): Webhook {
			$this->statistics[] = $success;
			return $webhook;
		});

		$logMapper = $this->createMock(WebhookLogMapper::class);
		$logMapper->method('insert')->willReturnCallback(function (WebhookLog $log): WebhookLog {
			$this->logs[] = $log;
			return $log;
		});

		$registry = $this->createMock(BreakerRegistry::class);
		$registry->method('breaker')->willReturn($this->breaker);

		$service = new WebhookService(
			stores: new WebhookStores(
				webhookMapper: $webhookMapper,
				webhookLogMapper: $logMapper,
				mappingMapper: $this->createMock(MappingMapper::class),
				mappingService: $this->createMock(MappingService::class)
			),
			dispatch: new WebhookDispatchServices(jobList: $this->createMock(IJobList::class)),
			logger: new NullLogger(),
			breakers: new DependencyBreaker($registry)
		);

		$client = $this->createMock(GuzzleClient::class);
		$client->method('request')->willReturnCallback(function (): GuzzleResponse {
			$this->requests++;
			if ($this->answer === 0) {
				throw new ConnectException('Connection refused', new GuzzleRequest('POST', 'https://93.184.216.34/hook'));
			}

			return new GuzzleResponse($this->answer, [], 'body');
		});
		(new ReflectionProperty(WebhookService::class, 'client'))->setValue($service, $client);

		return $service;
	}

	private static function webhook(): Webhook {
		$webhook = new Webhook();
		$webhook->setId(7);
		$webhook->setUuid('webhook-uuid-7');
		$webhook->setName('Receiver');
		$webhook->setUrl('https://93.184.216.34/hook');
		$webhook->setMethod('POST');
		$webhook->setEnabled(true);
		$webhook->setMaxRetries(5);
		$webhook->setRetryPolicy('fixed');
		return $webhook;
	}

	private static function delivery(int $attempt = 1, ?int $timeoutCap = null): WebhookDelivery {
		return new WebhookDelivery(
			webhook: self::webhook(),
			event: new WebhookEvent(name: 'object.updated', payload: ['id' => 5]),
			attempt: $attempt,
			timeoutCapSeconds: $timeoutCap
		);
	}

	private function openTheBreaker(WebhookService $service): void {
		$this->answer = 503;
		for ($i = 0; $i < 10; $i++) {
			$this->assertFalse($service->deliverWebhook(self::delivery()));
		}

		$this->assertSame('open', $this->breaker->state(self::KEY)['state']);
	}

	public function testADeliveredWebhookIsCountedAsASuccess(): void {
		$this->assertTrue($this->service()->deliverWebhook(self::delivery()));

		$state = $this->breaker->state(self::KEY);
		$this->assertSame(1, $state['calls']);
		$this->assertSame(0, $state['failures']);
	}

	public function testA404IsTheCallersProblemAndNeverOpensTheBreaker(): void {
		$service      = $this->service();
		$this->answer = 404;
		for ($i = 0; $i < 12; $i++) {
			$service->deliverWebhook(self::delivery());
		}

		$state = $this->breaker->state(self::KEY);
		$this->assertSame('closed', $state['state']);
		$this->assertSame(0, $state['failures']);
		$this->assertSame(12, $this->requests);
	}

	public function testConnectionErrorsOpenTheBreaker(): void {
		$service      = $this->service();
		$this->answer = 0;
		for ($i = 0; $i < 10; $i++) {
			$service->deliverWebhook(self::delivery());
		}

		$this->assertSame('open', $this->breaker->state(self::KEY)['state']);
	}

	public function testAnOpenBreakerDefersTheDeliveryWithoutSendingOrCountingAnAttempt(): void {
		$service = $this->service();
		$this->openTheBreaker(service: $service);
		$sent       = $this->requests;
		$statistics = count($this->statistics);
		$this->logs = [];

		$before = time();
		$this->assertFalse($service->deliverWebhook(self::delivery(attempt: 3)));

		$this->assertSame($sent, $this->requests, 'Nothing reaches the receiver while its breaker is open.');
		$this->assertCount($statistics, $this->statistics, 'A deferred delivery is not a delivery attempt.');
		$this->assertCount(1, $this->logs);
		$log = $this->logs[0];
		$this->assertFalse($log->getSuccess());
		$this->assertSame(2, $log->getAttempt(), 'The retry job sends attempt + 1, so it sends attempt 3 again.');
		$this->assertNotNull($log->getNextRetryAt());
		$delay = $log->getNextRetryAt()->getTimestamp() - $before;
		$this->assertGreaterThanOrEqual(29, $delay);
		$this->assertLessThanOrEqual(31, $delay);
		$this->assertSame('Receiver not answering; delivery deferred for 30 seconds', $log->getErrorMessage());
	}

	public function testAnInterceptionDeliveryIsSkippedAtOnceWhileTheBreakerIsOpen(): void {
		$service = $this->service();
		$this->openTheBreaker(service: $service);
		$sent = $this->requests;

		$this->assertFalse($service->deliverWebhook(self::delivery(timeoutCap: 2)));
		$this->assertSame($sent, $this->requests);
	}

	public function testAGoodDeliveryAfterTheCoolDownClosesTheBreaker(): void {
		$service = $this->service();
		$this->openTheBreaker(service: $service);

		$this->now   += 31;
		$this->answer = 200;
		$this->assertTrue($service->deliverWebhook(self::delivery()));
		$this->assertSame('closed', $this->breaker->state(self::KEY)['state']);
	}
}
