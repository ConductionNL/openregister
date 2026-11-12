<?php

/**
 * Golden coverage for what JobScheduleService stores and reads back.
 *
 * Pins the clamping of an administered interval and window hours, the exact
 * JSON written, and how a stored schedule with loose types or missing parts
 * reads back.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Operations
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Operations;

use OCA\OpenRegister\Service\Operations\JobScheduleChange;
use OCA\OpenRegister\Service\Operations\JobScheduleService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Locks the stored form of a job schedule.
 */
final class JobScheduleStoredGoldenTest extends TestCase {

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

	/**
	 * A service over an in-memory app config.
	 *
	 * @return JobScheduleService The service.
	 */
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
	}//end service()

	/**
	 * Administered values are clamped and stored as one JSON object.
	 *
	 * @return void
	 */
	public function testAdministeredValuesAreClampedAndStored(): void {
		$row = $this->service()->administer(
			self::JOB,
			new JobScheduleChange(enabled: false, intervalSeconds: 10, windowStartHour: -3, windowEndHour: 30)
		);

		$this->assertFalse($row['enabled']);
		$this->assertSame(60, $row['intervalSeconds']);
		$this->assertSame(0, $row['windowStartHour']);
		$this->assertSame(23, $row['windowEndHour']);
		$this->assertSame(
			['{"enabled":false,"intervalSeconds":60,"windowStartHour":0,"windowEndHour":23}'],
			array_values($this->stored)
		);
	}//end testAdministeredValuesAreClampedAndStored()

	/**
	 * An empty change stores the default schedule unchanged.
	 *
	 * @return void
	 */
	public function testAnEmptyChangeStoresTheDefault(): void {
		$this->service()->administer(self::JOB, new JobScheduleChange());

		$this->assertSame(
			['{"enabled":true,"intervalSeconds":3600,"windowStartHour":null,"windowEndHour":null}'],
			array_values($this->stored)
		);
	}//end testAnEmptyChangeStoresTheDefault()

	/**
	 * A stored schedule with loose types reads back cast, and a short interval is raised.
	 *
	 * @return void
	 */
	public function testALooselyTypedStoredScheduleReadsBackCast(): void {
		$service = $this->service();
		$service->administer(self::JOB, new JobScheduleChange());
		$key = array_key_first($this->stored);
		$this->stored[$key] = '{"enabled":0,"intervalSeconds":"5","windowStartHour":"7","windowEndHour":"19"}';

		$row = $service->describe(self::JOB);

		$this->assertFalse($row['enabled']);
		$this->assertSame(60, $row['intervalSeconds']);
		$this->assertSame(7, $row['windowStartHour']);
		$this->assertSame(19, $row['windowEndHour']);
	}//end testALooselyTypedStoredScheduleReadsBackCast()

	/**
	 * Missing parts of a stored schedule fall back to the defaults.
	 *
	 * @return void
	 */
	public function testMissingStoredPartsTakeTheDefaults(): void {
		$service = $this->service();
		$service->administer(self::JOB, new JobScheduleChange());
		$key = array_key_first($this->stored);
		$this->stored[$key] = '{"windowEndHour":null}';

		$row = $service->describe(self::JOB);

		$this->assertTrue($row['enabled']);
		$this->assertSame(3600, $row['intervalSeconds']);
		$this->assertNull($row['windowStartHour']);
		$this->assertNull($row['windowEndHour']);
	}//end testMissingStoredPartsTakeTheDefaults()
}//end class
