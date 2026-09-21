<?php

/**
 * Unit tests for JobScheduleService — enabled, the interval and the window.
 *
 * "It has no next due time and does not run" is one sentence in the spec and
 * two separate behaviours in the code: the row the console renders, and the
 * gate the job passes through. A schedule that reported no next due time but
 * still allowed the run would satisfy a reader looking at the console and
 * nothing else, so both are asserted.
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
use OCA\OpenRegister\Service\Operations\JobScheduleService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class JobScheduleServiceTest extends TestCase {

	/**
	 * The job every case administers.
	 *
	 * @var string
	 */
	private const JOB = 'Acme\\NightlyJob';

	/**
	 * What has been stored.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	private function service(): JobScheduleService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				$this->stored[$key] = $value;
			}
		);

		return new JobScheduleService($config);
	}

	/**
	 * A job nobody administered is enabled, and due.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 *
	 * @return void
	 */
	public function testAnUnadministeredJobIsEnabledAndDue(): void {
		$row = $this->service()->describe(self::JOB);

		$this->assertTrue($row['enabled']);
		$this->assertNotNull($row['nextDue']);
		$this->assertNull($row['lastRun']);
	}

	/**
	 * A disabled job has no next due time AND is not allowed to run.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 *
	 * @return void
	 */
	public function testADisabledJobHasNoNextDueTimeAndMayNotRun(): void {
		$service = $this->service();
		$row = $service->administer(self::JOB, false);

		$this->assertFalse($row['enabled']);
		$this->assertNull($row['nextDue'], 'A disabled job still reported a next due time.');
		$this->assertFalse(
			$service->mayRun(self::JOB, new DateTime()),
			'A disabled job was still allowed to run.'
		);
	}

	/**
	 * Enabling it again brings the next due time back.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 *
	 * @return void
	 */
	public function testEnablingItAgainMakesItDue(): void {
		$service = $this->service();
		$service->administer(self::JOB, false);

		$row = $service->administer(self::JOB, true);

		$this->assertTrue($row['enabled']);
		$this->assertNotNull($row['nextDue']);
		$this->assertTrue($service->mayRun(self::JOB, new DateTime()));
	}

	/**
	 * The row carries the last run and the next due time, an interval apart.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 *
	 * @return void
	 */
	public function testTheRowCarriesTheLastRunAndTheNextDueAnIntervalApart(): void {
		$service = $this->service();
		$service->administer(self::JOB, null, 7200);

		$lastRun = (new DateTime('2026-09-18 04:00:00'))->getTimestamp();
		$row = $service->describe(self::JOB, $lastRun);

		$this->assertSame(7200, $row['intervalSeconds']);
		$this->assertSame(
			($lastRun + 7200),
			(new DateTime($row['nextDue']))->getTimestamp()
		);
	}

	/**
	 * A window keeps the job out of the hours it was not given.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 *
	 * @return void
	 */
	public function testAWindowRefusesTheHoursOutsideIt(): void {
		$service = $this->service();
		$service->administer(self::JOB, null, null, 1, 5);

		$this->assertTrue($service->mayRun(self::JOB, new DateTime('2026-09-18 03:00:00')));
		$this->assertFalse($service->mayRun(self::JOB, new DateTime('2026-09-18 13:00:00')));
	}

	/**
	 * A window that wraps midnight is a window, not an empty one.
	 *
	 * A nightly job is administered as 22 to 6 far more often than as 0 to 6,
	 * and a naive start-to-end comparison reads 22 to 6 as "never".
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 *
	 * @return void
	 */
	public function testAWindowAcrossMidnightAllowsBothSidesOfIt(): void {
		$service = $this->service();
		$service->administer(self::JOB, null, null, 22, 6);

		$this->assertTrue($service->mayRun(self::JOB, new DateTime('2026-09-18 23:30:00')));
		$this->assertTrue($service->mayRun(self::JOB, new DateTime('2026-09-18 05:30:00')));
		$this->assertFalse($service->mayRun(self::JOB, new DateTime('2026-09-18 12:00:00')));
	}

	/**
	 * Administering one field leaves the others as they were.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 *
	 * @return void
	 */
	public function testAdministeringOneFieldDoesNotResetTheOthers(): void {
		$service = $this->service();
		$service->administer(self::JOB, null, 7200, 22, 6);

		$row = $service->administer(self::JOB, false);

		$this->assertFalse($row['enabled']);
		$this->assertSame(7200, $row['intervalSeconds']);
		$this->assertSame(22, $row['windowStartHour']);
		$this->assertSame(6, $row['windowEndHour']);
	}
}
