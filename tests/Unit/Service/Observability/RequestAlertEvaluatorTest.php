<?php

/**
 * The error rate and response time alert thresholds (production-observability, "Error rate threshold", "Response time threshold").
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Observability;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Service\Observability\RequestAlertEvaluator;
use OCA\OpenRegister\Service\Observability\RequestMetricsStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Observability\RequestAlertEvaluator
 */
final class RequestAlertEvaluatorTest extends TestCase {

	/**
	 * App config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The request rows the store answers.
	 *
	 * @var array<int, array{method: string, route: string, status: string, count: int, sumMs: int}>
	 */
	private array $requests = [];

	/**
	 * The bucket rows the store answers.
	 *
	 * @var array<int, array{method: string, route: string, bucket: string, count: int}>
	 */
	private array $buckets = [];

	/**
	 * Notifications sent, as [user, subject, parameters].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $sent = [];

	/**
	 * The clock.
	 *
	 * @var integer
	 */
	private int $now = 1_794_895_200;

	/**
	 * Whether notifying throws.
	 *
	 * @var boolean
	 */
	private bool $notifyFails = false;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	public function testTheFirstRunOnlyStoresABaseline(): void {
		$this->config = ['error_rate_alert_percent' => '5'];
		$this->traffic(requests: 100, errors: 50);

		$this->assertSame(['errorRateExceeded' => false, 'responseTimeExceeded' => false], $this->evaluator()->evaluate());
		$this->assertSame([], $this->sent);
		$snapshot = json_decode($this->config['request_alert_snapshot'], true);
		$this->assertSame([$this->now, 100, 50], [$snapshot['at'], $snapshot['requests'], $snapshot['errors']]);
	}//end testTheFirstRunOnlyStoresABaseline()

	public function testAnErrorRateOverTheThresholdAlertsEveryAdministratorOnce(): void {
		$this->config = ['error_rate_alert_percent' => '5'];
		$this->traffic(requests: 1000, errors: 10);
		$this->evaluator()->evaluate();

		$this->now += 300;
		$this->traffic(requests: 1100, errors: 16);
		$warnings = [];
		$this->logger->expects($this->exactly(2))->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$this->assertTrue($this->evaluator()->evaluate()['errorRateExceeded']);
		$expected = ['ratePercent' => 6.0, 'thresholdPercent' => 5, 'windowMinutes' => 5];
		$this->assertSame([['alice', 'observability_error_rate', $expected], ['bob', 'observability_error_rate', $expected]], $this->sent);
		$this->assertTrue($this->evaluator()->outcomes()['errorRateExceeded']);

		// Still over in the next window: no second alert.
		$this->now += 300;
		$this->traffic(requests: 1200, errors: 26);
		$this->assertTrue($this->evaluator()->evaluate()['errorRateExceeded']);
		$this->assertCount(2, $this->sent);

		// Below, then over again: a new breach alerts again.
		$this->now += 300;
		$this->traffic(requests: 1300, errors: 26);
		$this->assertFalse($this->evaluator()->evaluate()['errorRateExceeded']);
		$this->now += 300;
		$this->traffic(requests: 1400, errors: 36);
		$this->assertTrue($this->evaluator()->evaluate()['errorRateExceeded']);
		$this->assertCount(4, $this->sent);
		$this->assertStringContainsString('error rate 6% over the last 5 minutes', $warnings[0]);
	}//end testAnErrorRateOverTheThresholdAlertsEveryAdministratorOnce()

	public function testAnErrorRateAtTheThresholdDoesNotAlert(): void {
		$this->config = ['error_rate_alert_percent' => '5'];
		$this->traffic(requests: 0, errors: 0);
		$this->evaluator()->evaluate();
		$this->traffic(requests: 100, errors: 5);

		$this->assertFalse($this->evaluator()->evaluate()['errorRateExceeded']);
		$this->assertSame([], $this->sent);
	}//end testAnErrorRateAtTheThresholdDoesNotAlert()

	public function testWithoutAThresholdNothingIsJudged(): void {
		foreach (['', '0', 'five'] as $value) {
			$this->config = ['error_rate_alert_percent' => $value, 'response_time_p95_alert_ms' => $value];
			$this->traffic(requests: 0, errors: 0);
			$this->evaluator()->evaluate();
			$this->traffic(requests: 10, errors: 10, slow: 10);

			$this->assertSame(['errorRateExceeded' => false, 'responseTimeExceeded' => false], $this->evaluator()->evaluate());
		}

		$this->assertSame([], $this->sent);
	}//end testWithoutAThresholdNothingIsJudged()

	public function testAP95ResponseTimeOverTheThresholdAlerts(): void {
		$this->config = ['response_time_p95_alert_ms' => '3000'];
		$this->traffic(requests: 0, errors: 0);
		$this->evaluator()->evaluate();

		// 90 fast requests and 10 in the 2.5-5 s bucket: p95 is half-way into that bucket.
		$this->now += 300;
		$this->traffic(requests: 100, errors: 0, slow: 10);

		$this->assertTrue($this->evaluator()->evaluate()['responseTimeExceeded']);
		$this->assertSame(
			[['alice', 'observability_response_time', ['p95Ms' => 3750, 'thresholdMs' => 3000, 'windowMinutes' => 5]]],
			array_slice($this->sent, 0, 1)
		);
	}//end testAP95ResponseTimeOverTheThresholdAlerts()

	public function testAFastWindowIsNotOverTheResponseTimeThreshold(): void {
		$this->config = ['response_time_p95_alert_ms' => '3000'];
		$this->traffic(requests: 0, errors: 0);
		$this->evaluator()->evaluate();
		$this->traffic(requests: 100, errors: 0, slow: 2);

		$this->assertFalse($this->evaluator()->evaluate()['responseTimeExceeded']);
	}//end testAFastWindowIsNotOverTheResponseTimeThreshold()

	public function testAWindowWithoutRequestsKeepsThePreviousOutcome(): void {
		$this->config = ['error_rate_alert_percent' => '5'];
		$this->traffic(requests: 0, errors: 0);
		$this->evaluator()->evaluate();
		$this->traffic(requests: 10, errors: 10);
		$this->evaluator()->evaluate();

		$this->assertTrue($this->evaluator()->evaluate()['errorRateExceeded']);
		$this->assertCount(2, $this->sent);
	}//end testAWindowWithoutRequestsKeepsThePreviousOutcome()

	public function testTotalsThatWentDownStartANewBaseline(): void {
		$this->config = ['error_rate_alert_percent' => '5'];
		$this->traffic(requests: 0, errors: 0);
		$this->evaluator()->evaluate();
		$this->traffic(requests: 100, errors: 100);
		$this->evaluator()->evaluate();
		$this->traffic(requests: 50, errors: 50);

		$this->assertFalse($this->evaluator()->evaluate()['errorRateExceeded']);
	}//end testTotalsThatWentDownStartANewBaseline()

	public function testOutcomesAreOffWhenTheThresholdIsRemoved(): void {
		$this->config = ['error_rate_alert_percent' => '5'];
		$this->traffic(requests: 0, errors: 0);
		$this->evaluator()->evaluate();
		$this->traffic(requests: 10, errors: 10);
		$this->evaluator()->evaluate();
		unset($this->config['error_rate_alert_percent']);

		$this->assertSame(['errorRateExceeded' => false, 'responseTimeExceeded' => false], $this->evaluator()->outcomes());
	}//end testOutcomesAreOffWhenTheThresholdIsRemoved()

	public function testAFailedDeliveryIsLoggedAndTheOutcomeStands(): void {
		$this->config = ['error_rate_alert_percent' => '5'];
		$this->traffic(requests: 0, errors: 0);
		$this->evaluator()->evaluate();
		$this->traffic(requests: 10, errors: 10);
		$this->notifyFails = true;
		$this->logger->expects($this->exactly(2))->method('warning');

		$this->assertTrue($this->evaluator()->evaluate()['errorRateExceeded']);
	}//end testAFailedDeliveryIsLoggedAndTheOutcomeStands()

	public function testTheLastWindowIsSummarisedForTheDashboard(): void {
		$this->traffic(requests: 100, errors: 0);
		$this->assertNull($this->evaluator()->lastWindow());
		$this->evaluator()->evaluate();
		$this->assertNull($this->evaluator()->lastWindow());

		$this->now += 300;
		$this->traffic(requests: 150, errors: 4);
		$this->evaluator()->evaluate();

		$this->assertSame(['minutes' => 5, 'requests' => 50, 'errors' => 4, 'averageMs' => 10], $this->evaluator()->lastWindow());
	}//end testTheLastWindowIsSummarisedForTheDashboard()

	public function testAWindowWithoutTimedRequestsHasNoAverage(): void {
		$this->traffic(requests: 0, errors: 0);
		$this->evaluator()->evaluate();
		$this->evaluator()->evaluate();

		$this->assertSame(['minutes' => 1, 'requests' => 0, 'errors' => 0, 'averageMs' => null], $this->evaluator()->lastWindow());
	}//end testAWindowWithoutTimedRequestsHasNoAverage()

	public function testPercentileMs(): void {
		$this->assertNull(RequestAlertEvaluator::percentileMs(buckets: []));
		$this->assertSame(9.5, RequestAlertEvaluator::percentileMs(buckets: ['10' => 10]));
		$this->assertEqualsWithDelta(973.68, RequestAlertEvaluator::percentileMs(buckets: ['500' => 5, '1000' => 95]), 0.01);
		$this->assertSame(10000.0, RequestAlertEvaluator::percentileMs(buckets: ['10' => 1, 'inf' => 99]));
	}//end testPercentileMs()

	/**
	 * Set the cumulative totals: requests, 5xx answers and requests in the 2.5-5 s bucket (the rest under 10 ms).
	 *
	 * @param int $requests All requests.
	 * @param int $errors   The 5xx answers among them.
	 * @param int $slow     Requests that took between 2.5 and 5 s.
	 *
	 * @return void
	 */
	private function traffic(int $requests, int $errors, int $slow = 0): void {
		$this->requests = [
			['method' => 'GET', 'route' => 'objects.index', 'status' => '200', 'count' => ($requests - $errors), 'sumMs' => (10 * $requests)],
			['method' => 'GET', 'route' => 'objects.index', 'status' => '503', 'count' => $errors, 'sumMs' => 0],
		];
		$this->buckets = [
			['method' => 'GET', 'route' => 'objects.index', 'bucket' => '10', 'count' => ($requests - $slow)],
			['method' => 'GET', 'route' => 'objects.index', 'bucket' => '5000', 'count' => $slow],
		];
	}//end traffic()

	private function evaluator(): RequestAlertEvaluator {
		$store = $this->createMock(RequestMetricsStore::class);
		$store->method('requestTotals')->willReturnCallback(fn (): array => $this->requests);
		$store->method('bucketTotals')->willReturnCallback(fn (): array => $this->buckets);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame('openregister', $app);
				$this->config[$key] = $value;
				return true;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
		$notifications->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				if ($this->notifyFails === true) {
					throw new RuntimeException('no notifications app');
				}

				$this->sent[] = [$notification->getUser(), $notification->getSubject(), $notification->getSubjectParameters()];
			}
		);

		$users = [];
		foreach (['alice', 'bob'] as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturn($group);

		return new RequestAlertEvaluator(
			store: $store,
			appConfig: $appConfig,
			time: $time,
			notifications: $notifications,
			groups: $groups,
			logger: $this->logger
		);
	}//end evaluator()

	private function notification(): INotification {
		$state = ['user' => '', 'subject' => '', 'parameters' => []];
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setUser')->willReturnCallback(
			function (string $user) use (&$state, $notification): INotification {
				$state['user'] = $user;
				return $notification;
			}
		);
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $parameters) use (&$state, $notification): INotification {
				$state['subject'] = $subject;
				$state['parameters'] = $parameters;
				return $notification;
			}
		);
		$notification->method('getUser')->willReturnCallback(static function () use (&$state): string {
			return $state['user'];
		});
		$notification->method('getSubject')->willReturnCallback(static function () use (&$state): string {
			return $state['subject'];
		});
		$notification->method('getSubjectParameters')->willReturnCallback(static function () use (&$state): array {
			return $state['parameters'];
		});

		return $notification;
	}//end notification()
}//end class
