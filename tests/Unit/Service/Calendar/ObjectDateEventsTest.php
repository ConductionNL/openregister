<?php

/**
 * Unit tests for ObjectDateEvents, the generator output both calendar surfaces read.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Calendar;

use DateTimeZone;
use OCA\OpenRegister\Service\Calendar\ObjectDateEvents;
use PHPUnit\Framework\TestCase;

class ObjectDateEventsTest extends TestCase {

	public function testLinesAndYearsConcatenateEveryEventInOrder(): void {
		$events = new ObjectDateEvents(
			name: 'Bezwaren',
			timeZone: new DateTimeZone('Europe/Amsterdam'),
			events: [
				['lines' => ['BEGIN:VEVENT', 'UID:a', 'END:VEVENT'], 'years' => [2026, 2026]],
				['lines' => ['BEGIN:VEVENT', 'UID:b', 'END:VEVENT'], 'years' => [2027, 2028]],
			]
		);

		$this->assertSame(
			['BEGIN:VEVENT', 'UID:a', 'END:VEVENT', 'BEGIN:VEVENT', 'UID:b', 'END:VEVENT'],
			$events->lines()
		);
		$this->assertSame([2026, 2026, 2027, 2028], $events->years());
		$this->assertSame('Bezwaren', $events->name);
		$this->assertSame('Europe/Amsterdam', $events->timeZone->getName());
	}

	public function testNoEventsHaveNoLinesAndNoYears(): void {
		$events = new ObjectDateEvents(name: 'Leeg', timeZone: new DateTimeZone('UTC'), events: []);

		$this->assertSame([], $events->lines());
		$this->assertSame([], $events->years());
	}
}
