<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Formats;

use OCA\OpenRegister\Formats\FormatFactory;
use OCA\OpenRegister\Formats\TimeZoneFormat;
use PHPUnit\Framework\TestCase;

/**
 * The `time-zone` format accepts an IANA zone name and nothing that only looks like one.
 *
 * @covers \OCA\OpenRegister\Formats\TimeZoneFormat
 * @uses   \OCA\OpenRegister\Formats\FormatFactory
 * @uses   \OCA\OpenRegister\Formats\UserFormat
 *
 * @spec openspec/changes/calendar-time-zone/tasks.md
 */
class TimeZoneFormatTest extends TestCase {

	public function testAnIanaZoneNameIsAccepted(): void {
		$format = new TimeZoneFormat();

		foreach (['Europe/Amsterdam', 'UTC', 'America/Curacao', 'Asia/Kolkata'] as $zone) {
			$this->assertTrue($format->validate($zone), $zone);
		}
	}

	public function testOffsetsAbbreviationsAndNonStringsAreRefused(): void {
		$format = new TimeZoneFormat();

		foreach (['+02:00', 'CET', 'europe/amsterdam', ' Europe/Amsterdam', 'Mars/Olympus', '', 'Europe/'] as $zone) {
			$this->assertFalse($format->validate($zone), $zone);
		}

		$this->assertFalse($format->validate(null));
		$this->assertFalse($format->validate(1));
	}

	public function testTheFormatIsRegisteredAsTimeZone(): void {
		$formats = FormatFactory::customStringFormats($this->createMock(\OCP\IUserManager::class), static fn (string $uid): bool => false);

		$this->assertInstanceOf(TimeZoneFormat::class, $formats['time-zone']);
	}
}
