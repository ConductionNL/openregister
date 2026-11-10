<?php

/**
 * When a job whose next run falls outside its administered window is next
 * due, and what a corrupt stored schedule reads as.
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

use OCA\OpenRegister\Service\Operations\JobScheduleService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Window tests for {@see JobScheduleService}.
 */
class JobScheduleServiceWindowTest extends TestCase {

	/**
	 * The timezone in force before the test.
	 *
	 * @var string
	 */
	private string $timezone = 'UTC';

	/**
	 * Pin the timezone: the window is read in server-local hours.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->timezone = date_default_timezone_get();
		date_default_timezone_set('UTC');
	}//end setUp()

	/**
	 * Restore the timezone.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		date_default_timezone_set($this->timezone);
	}//end tearDown()

	/**
	 * A service whose stored schedule for every job is the given value.
	 *
	 * @param string $stored The stored JSON.
	 *
	 * @return JobScheduleService The service.
	 */
	private function service(string $stored): JobScheduleService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn($stored);

		return new JobScheduleService(config: $config);
	}//end service()

	/**
	 * A run due before the window opens on the same day waits for the next
	 * day's opening.
	 *
	 * @return void
	 */
	public function testADueMomentPastTodaysWindowMovesToTomorrowsOpening(): void {
		$service = $this->service('{"enabled":true,"intervalSeconds":3600,"windowStartHour":2,"windowEndHour":4}');
		$lastRun = strtotime('2026-01-01T10:00:00Z');

		$described = $service->describe(jobClass: 'Acme\\Job', lastRun: $lastRun);

		$this->assertSame('2026-01-02T02:00:00+00:00', $described['nextDue']);
		$this->assertSame('2026-01-01T10:00:00+00:00', $described['lastRun']);
	}//end testADueMomentPastTodaysWindowMovesToTomorrowsOpening()

	/**
	 * A window that opens later the same day is due at that opening, not a
	 * day later.
	 *
	 * @return void
	 */
	public function testADueMomentBeforeTodaysWrappingWindowWaitsForItsOpening(): void {
		$service = $this->service('{"enabled":true,"intervalSeconds":3600,"windowStartHour":22,"windowEndHour":4}');

		$described = $service->describe(jobClass: 'Acme\\Job', lastRun: strtotime('2026-01-01T10:00:00Z'));

		$this->assertSame('2026-01-01T22:00:00+00:00', $described['nextDue']);
		$this->assertSame([22, 4], [$described['windowStartHour'], $described['windowEndHour']]);
	}//end testADueMomentBeforeTodaysWrappingWindowWaitsForItsOpening()

	/**
	 * A due moment inside the window is the plain interval after the last run.
	 *
	 * @return void
	 */
	public function testADueMomentInsideTheWindowIsTheInterval(): void {
		$service = $this->service('{"intervalSeconds":7200,"windowStartHour":8,"windowEndHour":18}');

		$this->assertSame('2026-01-01T12:00:00+00:00', $service->describe(jobClass: 'Acme\\Job', lastRun: strtotime('2026-01-01T10:00:00Z'))['nextDue']);
	}//end testADueMomentInsideTheWindowIsTheInterval()

	/**
	 * A stored value that is JSON but not an object reads as the default
	 * schedule.
	 *
	 * @return void
	 */
	public function testANonObjectStoredScheduleReadsAsTheDefault(): void {
		$described = $this->service('"corrupt"')->describe(jobClass: 'Acme\\Job');

		$this->assertTrue($described['enabled']);
		$this->assertSame(JobScheduleService::DEFAULT_INTERVAL_SECONDS, $described['intervalSeconds']);
		$this->assertNull($described['windowStartHour']);
		$this->assertNull($described['lastRun']);
	}//end testANonObjectStoredScheduleReadsAsTheDefault()
}//end class
