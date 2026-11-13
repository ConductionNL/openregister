<?php

/**
 * TermQuery: the question a term diagnostic answers, and the start it measures from.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\Flow\Timer\TermQuery;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\TermQuery
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinition
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarHolidays
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarRules
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursDeclaration
 */
class TermQueryTest extends TestCase {

	/**
	 * The start is an immutable copy of the anchor, the same instant and zone, unaffected by later changes to it.
	 *
	 * @return void
	 */
	public function testTheStartIsAnImmutableCopyOfTheAnchor(): void {
		$calendar = WorkingCalendar::fromArray(['slug' => 'standard', 'hoursPerWorkingDay' => 8, 'workingWeekdays' => [1, 2, 3, 4, 5]]);
		$anchor   = new DateTime('2026-11-12 09:15:00', new DateTimeZone('Europe/Amsterdam'));
		$query    = new TermQuery(calendar: $calendar, anchor: $anchor, sla: ['days' => 5]);

		$start = $query->start();
		$anchor->modify('+1 day');

		$this->assertInstanceOf(DateTimeImmutable::class, $start);
		$this->assertSame('2026-11-12 09:15:00 Europe/Amsterdam', $start->format('Y-m-d H:i:s e'));
		$this->assertSame(['days' => 5], $query->sla);
		$this->assertSame([], $query->ladder);
		$this->assertSame($calendar, $query->calendar);

	}//end testTheStartIsAnImmutableCopyOfTheAnchor()
}//end class
