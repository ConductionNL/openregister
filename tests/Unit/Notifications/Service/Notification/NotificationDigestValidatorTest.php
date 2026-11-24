<?php

/**
 * NotificationDigestValidator: the faults a notification digest schedule can carry.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Notifications\Service\Notification\NotificationDigestValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\NotificationDigestValidator
 */
class NotificationDigestValidatorTest extends TestCase {

	/**
	 * The validator under test.
	 *
	 * @var NotificationDigestValidator
	 */
	private NotificationDigestValidator $validator;

	protected function setUp(): void {
		$this->validator = new NotificationDigestValidator();
	}

	/**
	 * The codes of the faults reported for a spec.
	 *
	 * @param array $spec The notification spec.
	 *
	 * @return array<string>
	 */
	private function codes(array $spec): array {
		return array_column($this->validator->validate($spec, 'nightly'), 'code');
	}

	public function testANonObjectDigestIsTheOnlyFault(): void {
		$this->assertSame(
			[[
				'code' => 'notification-digest-malformed',
				'message' => 'Notification "nightly" `digest` must be an object.',
			]],
			$this->validator->validate(['digest' => 'daily', 'coalesce' => ['window' => 60]], 'nightly')
		);
	}

	public function testAValidDailyAndWeeklyDigestHaveNoFaults(): void {
		$this->assertSame([], $this->codes(['digest' => ['schedule' => 'daily', 'at' => '00:00']]));
		$this->assertSame([], $this->codes(['digest' => ['schedule' => 'daily', 'at' => '23:59', 'timezone' => 'Europe/Amsterdam']]));
		$this->assertSame([], $this->codes(['digest' => ['schedule' => 'weekly', 'at' => '09:30', 'weekday' => 0]]));
		$this->assertSame([], $this->codes(['digest' => ['schedule' => 'weekly', 'at' => '09:30', 'weekday' => 6]]));
	}

	public function testAnUnknownOrMissingScheduleIsRefused(): void {
		$this->assertSame(['notification-digest-bad-schedule'], $this->codes(['digest' => ['schedule' => 'hourly', 'at' => '08:00']]));
		$this->assertSame(['notification-digest-bad-schedule'], $this->codes(['digest' => ['at' => '08:00']]));
	}

	public function testTheTimeMustBeAZeroPaddedTwentyFourHourClock(): void {
		foreach (['24:00', '8:00', '08:60', '0800', 800, null] as $at) {
			$this->assertSame(
				['notification-digest-bad-time'],
				$this->codes(['digest' => ['schedule' => 'daily', 'at' => $at]]),
				'at = '.var_export($at, true)
			);
		}

		$this->assertSame([], $this->codes(['digest' => ['schedule' => 'daily', 'at' => '19:05']]));
	}

	public function testAWeekdayOnADailyDigestIsNotAllowed(): void {
		$this->assertSame(
			[[
				'code' => 'notification-digest-weekday-not-allowed',
				'message' => 'Notification "nightly" `digest.weekday` is only allowed when `schedule` is "weekly".',
			]],
			$this->validator->validate(['digest' => ['schedule' => 'daily', 'at' => '08:00', 'weekday' => 1]], 'nightly')
		);
	}

	public function testAWeeklyDigestNeedsAnIntegerWeekdayFromZeroToSix(): void {
		foreach ([7, -1, '1', null] as $weekday) {
			$this->assertSame(
				['notification-digest-weekly-missing-weekday'],
				$this->codes(['digest' => ['schedule' => 'weekly', 'at' => '08:00', 'weekday' => $weekday]]),
				'weekday = '.var_export($weekday, true)
			);
		}

		$this->assertSame(['notification-digest-weekly-missing-weekday'], $this->codes(['digest' => ['schedule' => 'weekly', 'at' => '08:00']]));
	}

	public function testATimezoneIsCheckedOnlyWhenGiven(): void {
		foreach (['Mars/Olympus', '', 42] as $timezone) {
			$this->assertSame(
				['notification-digest-bad-timezone'],
				$this->codes(['digest' => ['schedule' => 'daily', 'at' => '08:00', 'timezone' => $timezone]]),
				'timezone = '.var_export($timezone, true)
			);
		}
	}

	public function testDigestAndCoalesceTogetherAreRefusedAfterTheDigestFaults(): void {
		$this->assertSame(
			['notification-digest-bad-schedule', 'notification-digest-bad-time', 'notification-digest-and-coalesce-mutually-exclusive'],
			$this->codes(['digest' => [], 'coalesce' => ['window' => 300]])
		);
		$this->assertSame([], $this->codes(['digest' => ['schedule' => 'daily', 'at' => '08:00'], 'coalesce' => 'off']));
	}
}
