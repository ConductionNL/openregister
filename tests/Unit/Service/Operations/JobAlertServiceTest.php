<?php

/**
 * Unit tests for JobAlertService — the threshold, over a clock this test holds.
 *
 * The spec's example is three failures in one hour, and the interesting cases
 * are all about time: what counts as inside the period, what "more than three"
 * means at exactly three, and whether the fourth, fifth and sixth failure each
 * raise their own alert. None of that can be asserted against a real clock, so
 * the clock is a fixture here and the period is walked by moving it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Operations
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Operations;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\JobRun;
use OCA\OpenRegister\Db\JobRunMapper;
use OCA\OpenRegister\Service\Operations\JobAlertService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class JobAlertServiceTest extends TestCase {

	/**
	 * The moment the fixture clock stands at.
	 *
	 * @var integer
	 */
	private const NOW = 1800000000;

	/**
	 * The run log.
	 *
	 * @var JobRunMapper
	 */
	private JobRunMapper $runs;

	/**
	 * The stored settings and markers.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The failures the log answers with, oldest first.
	 *
	 * @var array<int, JobRun>
	 */
	private array $failures = [];

	/**
	 * The notifications that were sent.
	 *
	 * @var array<int, INotification>
	 */
	private array $sent = [];

	/**
	 * The window the log was asked about.
	 *
	 * @var DateTime|null
	 */
	private ?DateTime $askedSince = null;

	protected function setUp(): void {
		parent::setUp();

		$this->runs = $this->createMock(JobRunMapper::class);
		$this->runs->method('failuresSince')->willReturnCallback(
			function (string $jobClass, DateTime $since): array {
				$this->askedSince = $since;

				return array_values(
					array_filter(
						$this->failures,
						static fn (JobRun $run): bool => $run->getStarted() >= $since
					)
				);
			}
		);
	}

	/**
	 * A configuration that remembers what was written to it.
	 *
	 * @return IConfig The double.
	 */
	private function config(): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				$this->stored[$key] = $value;
			}
		);

		return $config;
	}

	/**
	 * A notification manager that keeps what it was asked to send.
	 *
	 * @return INotificationManager The double.
	 */
	private function notifications(): INotificationManager {
		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(
			fn (): INotification => $this->createMock(INotification::class)
		);
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$this->sent[] = $notification;
			}
		);

		return $manager;
	}

	/**
	 * A group manager with one administrator in it.
	 *
	 * @return IGroupManager The double.
	 */
	private function groupManager(): IGroupManager {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('noor');

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$user]);

		$manager = $this->createMock(IGroupManager::class);
		$manager->method('get')->willReturn($group);

		return $manager;
	}

	/**
	 * The service, with the clock held at NOW.
	 *
	 * @return JobAlertService The service under test.
	 */
	private function service(): JobAlertService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new JobAlertService(
			$this->runs,
			$this->config(),
			$this->notifications(),
			$this->groupManager(),
			$time,
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * Record a failure that happened this many minutes before NOW.
	 *
	 * @param int    $minutesAgo How long ago.
	 * @param string $message    What it said.
	 *
	 * @return void
	 */
	private function failed(int $minutesAgo, string $message = 'boom'): void {
		$run = new JobRun();
		$run->setId((count($this->failures) + 1));
		$run->setJobClass('Acme\\NightlyJob');
		$run->setOutcome(JobRun::OUTCOME_FAILED);
		$run->setMessage($message);
		$run->setStarted((new DateTime())->setTimestamp((self::NOW - ($minutesAgo * 60))));

		$this->failures[] = $run;
		usort(
			$this->failures,
			static fn (JobRun $a, JobRun $b): int => ($a->getStarted() <=> $b->getStarted())
		);
	}

	/**
	 * Three failures in the hour do not breach a threshold of three: the spec
	 * says MORE than the administered number.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 *
	 * @return void
	 */
	public function testThreeFailuresDoNotBreachAThresholdOfThree(): void {
		$this->failed(50);
		$this->failed(30);
		$this->failed(10);

		$this->assertNull($this->service()->observeFailure('Acme\\NightlyJob'));
		$this->assertSame([], $this->sent);
	}

	/**
	 * Four failures within the hour raise one alert, naming the job and the
	 * FIRST of those failures.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 *
	 * @return void
	 */
	public function testFourFailuresInTheHourRaiseOneAlertNamingTheFirst(): void {
		$this->failed(50, 'the first one');
		$this->failed(30);
		$this->failed(20);
		$this->failed(10);

		$alert = $this->service()->observeFailure('Acme\\NightlyJob');

		$this->assertNotNull($alert);
		$this->assertSame('Acme\\NightlyJob', $alert['job']);
		$this->assertSame(4, $alert['failures']);
		$this->assertSame('the first one', $alert['firstFailureMessage']);
		$this->assertSame((self::NOW - (50 * 60)), $alert['firstFailure']);
		$this->assertCount(1, $this->sent);
	}

	/**
	 * The period is the administered one, counted back from the clock.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 *
	 * @return void
	 */
	public function testAFailureOlderThanThePeriodIsOutsideIt(): void {
		$this->failed(200, 'yesterday, really');
		$this->failed(50);
		$this->failed(40);
		$this->failed(30);
		$this->failed(10);

		$alert = $this->service()->observeFailure('Acme\\NightlyJob');

		$this->assertNotNull($alert);
		$this->assertSame(4, $alert['failures'], 'The failure outside the hour was counted.');
		$this->assertSame('boom', $alert['firstFailureMessage'], 'The alert named a failure from outside the period.');
		$this->assertSame(
			(self::NOW - (60 * 60)),
			(int)$this->askedSince?->getTimestamp(),
			'The window did not start one administered hour before the clock.'
		);
	}

	/**
	 * A fifth failure inside the same breach stays quiet: one alert per
	 * breach, not one per failure over the line.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 *
	 * @return void
	 */
	public function testASecondFailureInTheSameBreachRaisesNoSecondAlert(): void {
		$this->failed(50);
		$this->failed(40);
		$this->failed(30);
		$this->failed(20);

		$this->assertNotNull($this->service()->observeFailure('Acme\\NightlyJob'));

		$this->failed(10);

		$this->assertNull(
			$this->service()->observeFailure('Acme\\NightlyJob'),
			'The same breach alerted twice.'
		);
		$this->assertCount(1, $this->sent);
	}

	/**
	 * A later breach, whose first failure is a different one, alerts again.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 *
	 * @return void
	 */
	public function testANewBreachAlertsAgain(): void {
		$this->failed(50);
		$this->failed(40);
		$this->failed(30);
		$this->failed(20);

		$this->assertNotNull($this->service()->observeFailure('Acme\\NightlyJob'));

		// The first four have aged out of the hour; four fresh ones happened.
		$this->failures = [];
		$this->failed(15);
		$this->failed(12);
		$this->failed(8);
		$this->failed(4);

		$second = $this->service()->observeFailure('Acme\\NightlyJob');

		$this->assertNotNull($second, 'A genuinely new breach was suppressed.');
		$this->assertSame((self::NOW - (15 * 60)), $second['firstFailure']);
		$this->assertCount(2, $this->sent);
	}

	/**
	 * The threshold and the period are administered, and what is administered
	 * is what the count is judged against.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 *
	 * @return void
	 */
	public function testTheAdministeredThresholdIsTheOneApplied(): void {
		$service = $this->service();
		$service->administer(1, 10);

		$this->assertSame(
			['threshold' => 1, 'periodMinutes' => 10],
			$service->settings()
		);

		$this->failed(5);
		$this->failed(3);

		$alert = $service->observeFailure('Acme\\NightlyJob');

		$this->assertNotNull($alert, 'Two failures did not breach an administered threshold of one.');
		$this->assertSame(1, $alert['threshold']);
		$this->assertSame((self::NOW - (10 * 60)), (int)$this->askedSince?->getTimestamp());
	}
}
