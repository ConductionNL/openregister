<?php

/**
 * What the job-failure alert delivers and to whom, what it does when it
 * cannot deliver, and the console's list of current breaches.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Operations;

use DateTime;
use OCA\OpenRegister\Db\JobRun;
use OCA\OpenRegister\Db\JobRunMapperInterface;
use OCA\OpenRegister\Service\Operations\JobAlertDelivery;
use OCA\OpenRegister\Service\Operations\JobAlertService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
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
 * Delivery tests for {@see JobAlertService}.
 */
class JobAlertServiceDeliveryTest extends TestCase {

	/**
	 * The frozen clock.
	 *
	 * @var int
	 */
	private const NOW = 1800000000;

	/**
	 * Stored app values.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * Failures per job class.
	 *
	 * @var array<string, array<int, JobRun>>
	 */
	private array $failures = [];

	/**
	 * The notification manager.
	 *
	 * @var INotificationManager&MockObject
	 */
	private INotificationManager&MockObject $notifications;

	/**
	 * The group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * What was set on each notification created, in order.
	 *
	 * @var array<int, \ArrayObject<string, array<int, mixed>>>
	 */
	private array $log = [];

	/**
	 * The service under test.
	 *
	 * @var JobAlertService
	 */
	private JobAlertService $service;

	/**
	 * Build the service over mocked boundaries and a frozen clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$runs = $this->createMock(JobRunMapperInterface::class);
		$runs->method('failuresSince')->willReturnCallback(
			fn (string $jobClass, DateTime $since): array => ($this->failures[$jobClass] ?? [])
		);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, $default = ''): string => ($this->stored[$key] ?? (string)$default)
		);
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, $value): void {
				$this->stored[$key] = (string)$value;
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->notifications = $this->createMock(INotificationManager::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new JobAlertService(
			runs: $runs,
			config: $config,
			time: $time,
			delivery: new JobAlertDelivery(notifications: $this->notifications, groups: $this->groups, logger: $this->logger)
		);
	}//end setUp()

	/**
	 * Record `count` failures of the job, the first `firstAgo` minutes ago.
	 *
	 * @param string $jobClass The job.
	 * @param int $count How many.
	 * @param int $firstAgo Minutes ago of the first.
	 *
	 * @return void
	 */
	private function failed(string $jobClass, int $count, int $firstAgo = 30): void {
		for ($i = 0; $i < $count; $i++) {
			$run = new JobRun();
			$run->setJobClass($jobClass);
			$run->setOutcome(JobRun::OUTCOME_FAILED);
			$run->setMessage('boom ' . $i);
			$run->setStarted((new DateTime())->setTimestamp(self::NOW - (($firstAgo - $i) * 60)));
			$this->failures[$jobClass][] = $run;
		}
	}//end failed()

	/**
	 * The admin group, with the given uids.
	 *
	 * @param array<int, string> $uids The members.
	 *
	 * @return void
	 */
	private function admins(array $uids): void {
		$users = array_map(
			function (string $uid): IUser {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);

				return $user;
			},
			$uids
		);
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		$this->groups->method('get')->with('admin')->willReturn($group);
	}//end admins()

	/**
	 * A notification that records what was set on it.
	 *
	 * @return INotification&MockObject The notification.
	 */
	private function recordingNotification(): INotification&MockObject {
		$entry = new \ArrayObject();
		$this->log[] = $entry;
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setObject', 'setSubject', 'setMessage'] as $setter) {
			$notification->method($setter)->willReturnCallback(
				function (...$args) use ($entry, $setter, $notification): INotification {
					$entry[$setter] = $args;

					return $notification;
				}
			);
		}

		$notification->method('setDateTime')->willReturnSelf();

		return $notification;
	}//end recordingNotification()

	/**
	 * A breach notifies every administrator with the job, count, period and
	 * the first failure, and marks the breach raised.
	 *
	 * @return void
	 */
	public function testABreachNotifiesEveryAdministrator(): void {
		$this->failed(jobClass: 'Acme\\Jobs\\NightlyJob', count: 4);
		$this->admins(uids: ['noor', 'sam']);
		$this->notifications->method('createNotification')->willReturnCallback(fn (): INotification => $this->recordingNotification());
		$this->notifications->expects($this->exactly(2))->method('notify');
		$this->logger->expects($this->never())->method('warning');

		$breach = $this->service->observeFailure(jobClass: 'Acme\\Jobs\\NightlyJob');

		$this->assertSame(4, $breach['failures']);
		$this->assertSame('NightlyJob', $breach['name']);
		$this->assertSame(self::NOW - 1800, $breach['firstFailure']);
		$this->assertSame(['noor', 'sam'], [$this->log[0]['setUser'][0], $this->log[1]['setUser'][0]]);
		$this->assertSame(['openregister'], $this->log[0]['setApp']);
		$this->assertSame(['operations_job_failure', 'Acme\\Jobs\\NightlyJob'], $this->log[0]['setObject']);
		$this->assertSame(['operations_job_failure', ['job' => 'NightlyJob', 'failures' => 4, 'periodMinutes' => 60]], $this->log[0]['setSubject']);
		$this->assertSame('boom 0', $this->log[1]['setMessage'][1]['firstFailureMessage']);
		$this->assertSame((string)(self::NOW - 1800), $this->stored['operations_alert_raised_' . md5('Acme\\Jobs\\NightlyJob')]);
	}//end testABreachNotifiesEveryAdministrator()

	/**
	 * Without an admin group nobody is notified, yet the breach is marked
	 * and returned.
	 *
	 * @return void
	 */
	public function testNoAdminGroupNotifiesNobodyButStillRaises(): void {
		$this->failed(jobClass: 'Solo', count: 4);
		$this->groups->method('get')->willReturn(null);
		$this->notifications->expects($this->never())->method('createNotification');

		$breach = $this->service->observeFailure(jobClass: 'Solo');

		$this->assertSame('Solo', $breach['name']);
		$this->assertArrayHasKey('operations_alert_raised_' . md5('Solo'), $this->stored);
	}//end testNoAdminGroupNotifiesNobodyButStillRaises()

	/**
	 * A delivery that throws is logged with the job, and the breach is still
	 * returned to the failing job's recorder.
	 *
	 * @return void
	 */
	public function testAFailedDeliveryIsLoggedAndSwallowed(): void {
		$this->failed(jobClass: 'Acme\\Flaky', count: 5);
		$this->admins(uids: ['noor']);
		$this->notifications->method('createNotification')->willThrowException(new RuntimeException('notifier down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'[JobAlertService] Could not deliver the alert',
				$this->callback(static fn (array $context): bool => $context['job'] === 'Acme\\Flaky' && $context['exception']->getMessage() === 'notifier down')
			);

		$this->assertSame(5, $this->service->observeFailure(jobClass: 'Acme\\Flaky')['failures']);
	}//end testAFailedDeliveryIsLoggedAndSwallowed()

	/**
	 * The same breach stays quiet the second time; a breach with a different
	 * first failure alerts again.
	 *
	 * @return void
	 */
	public function testTheSameBreachIsRaisedOnce(): void {
		$this->failed(jobClass: 'Acme\\Loop', count: 4);
		$this->groups->method('get')->willReturn(null);

		$this->assertNotNull($this->service->observeFailure(jobClass: 'Acme\\Loop'));
		$this->assertNull($this->service->observeFailure(jobClass: 'Acme\\Loop'));

		$this->stored['operations_alert_raised_' . md5('Acme\\Loop')] = '12345';
		$this->assertNotNull($this->service->observeFailure(jobClass: 'Acme\\Loop'));
	}//end testTheSameBreachIsRaisedOnce()

	/**
	 * The console lists only the jobs over the threshold; an empty list lists nothing.
	 *
	 * @return void
	 */
	public function testAlertsListOnlyTheBreachingJobs(): void {
		$this->failed(jobClass: 'Over', count: 4);
		$this->failed(jobClass: 'AtThreshold', count: 3);

		$this->notifications->expects($this->never())->method('createNotification');
		$this->assertNull($this->service->observeFailure(jobClass: 'AtThreshold'));
		$this->assertSame([], $this->stored, 'no marker for a job at the threshold');
		$this->assertSame(['Over'], array_column($this->service->alerts(jobClasses: ['AtThreshold', 'Over', 'Never']), 'job'));
		$this->assertSame([], $this->service->alerts(jobClasses: []));
	}//end testAlertsListOnlyTheBreachingJobs()

	/**
	 * A threshold administered lower makes three failures a breach.
	 *
	 * @return void
	 */
	public function testAdministeredSettingsChangeWhatBreaches(): void {
		$this->failed(jobClass: 'Three', count: 3);
		$this->assertSame([], $this->service->alerts(jobClasses: ['Three']));

		$this->assertSame(['threshold' => 2, 'periodMinutes' => 1], $this->service->administer(threshold: 2, periodMinutes: -4));
		$this->assertSame(['threshold' => 2, 'periodMinutes' => 1], $this->service->administer(threshold: null, periodMinutes: null));
		$this->assertSame(1, $this->service->alerts(jobClasses: ['Three'])[0]['periodMinutes']);
	}//end testAdministeredSettingsChangeWhatBreaches()
}//end class
