<?php

/**
 * The calendar whose first week of the year a facet query's week buckets follow.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use OCA\OpenRegister\Service\Object\FacetWeekNumbering;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\FacetWeekNumbering
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\CalendarBlackouts
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\CalendarWeekNumbering
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursDeclaration
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinition
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarHolidays
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarRules
 * @uses   \OCA\OpenRegister\Service\Object\FacetWeekLabels
 * @uses \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 */
class FacetWeekNumberingTest extends TestCase {

	/**
	 * A calendar declaring this first week, or ISO when null.
	 *
	 * @param string|null $firstWeek The declaration.
	 *
	 * @return WorkingCalendar
	 */
	private static function calendar(?string $firstWeek): WorkingCalendar {
		$definition = ['slug' => 'x', 'workingWeekdays' => [1, 2, 3, 4, 5], 'hoursPerWorkingDay' => 8, 'timezone' => 'UTC'];
		if ($firstWeek !== null) {
			$definition['firstWeekOfYear'] = $firstWeek;
		}

		return WorkingCalendar::fromArray($definition);
	}//end calendar()

	/**
	 * A schema naming a calendar, or none.
	 *
	 * @param string|null $calendar The calendar slug.
	 *
	 * @return Schema
	 */
	private static function schema(?string $calendar): Schema {
		$schema = new Schema();
		$configuration = ['other' => 'setting'];
		if ($calendar !== null) {
			$configuration['workingCalendar'] = $calendar;
		}

		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	/**
	 * The resolver over a calendar service expecting one resolve call.
	 *
	 * @param string|null      $slug     The slug the call must name.
	 * @param WorkingCalendar  $calendar What it answers.
	 *
	 * @return FacetWeekNumbering
	 */
	private function numbering(?string $slug, WorkingCalendar $calendar): FacetWeekNumbering {
		$calendars = $this->createMock(WorkingCalendarService::class);
		$calendars->expects($this->once())->method('resolve')->with($slug, null)->willReturn($calendar);

		return new FacetWeekNumbering(calendars: $calendars, logger: $this->createMock(LoggerInterface::class));
	}//end numbering()

	/**
	 * Schemas that all name one calendar follow that calendar's first week.
	 *
	 * @return void
	 */
	public function testSchemasNamingOneCalendarFollowIt(): void {
		$labels = $this->numbering(slug: 'stembus', calendar: self::calendar(firstWeek: 'containsJanuary1'))
			->labelsFor(schemas: [self::schema(calendar: 'stembus'), self::schema(calendar: ' stembus ')]);

		$this->assertNotNull($labels);
		$this->assertSame('containsJanuary1', $labels->declaration());
	}//end testSchemasNamingOneCalendarFollowIt()

	/**
	 * Schemas naming different calendars, or one naming none, follow the instance calendar.
	 *
	 * @return void
	 */
	public function testMixedSchemasFollowTheInstanceCalendar(): void {
		$this->assertSame(
			'firstFullWeek',
			$this->numbering(slug: null, calendar: self::calendar(firstWeek: 'firstFullWeek'))
				->labelsFor(schemas: [self::schema(calendar: 'a'), self::schema(calendar: 'b')])?->declaration()
		);
		$this->assertSame(
			'firstFullWeek',
			$this->numbering(slug: null, calendar: self::calendar(firstWeek: 'firstFullWeek'))
				->labelsFor(schemas: [self::schema(calendar: 'a'), self::schema(calendar: null)])?->declaration()
		);
		$this->assertSame(
			'firstFullWeek',
			$this->numbering(slug: null, calendar: self::calendar(firstWeek: 'firstFullWeek'))
				->labelsFor(schemas: [self::schema(calendar: 'a'), 'not a schema'])?->declaration()
		);
	}//end testMixedSchemasFollowTheInstanceCalendar()

	/**
	 * An ISO calendar leaves the buckets as the database keyed them.
	 *
	 * @return void
	 */
	public function testAnIsoCalendarChangesNothing(): void {
		$this->assertNull($this->numbering(slug: null, calendar: self::calendar(firstWeek: null))->labelsFor(schemas: []));
	}//end testAnIsoCalendarChangesNothing()

	/**
	 * A calendar that cannot be resolved leaves the buckets ISO and is noted.
	 *
	 * @return void
	 */
	public function testAnUnresolvableCalendarKeepsIsoWeeks(): void {
		$calendars = $this->createMock(WorkingCalendarService::class);
		$calendars->method('resolve')->willThrowException(new FlowTimerValidationException(message: 'no calendar gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'[FacetWeekNumbering] No working calendar resolved; week buckets stay ISO weeks.',
			$this->callback(static fn (array $context): bool => $context['calendar'] === 'gone' && $context['exception'] === 'no calendar gone')
		);

		$labels = (new FacetWeekNumbering(calendars: $calendars, logger: $logger))->labelsFor(schemas: [self::schema(calendar: 'gone')]);

		$this->assertNull($labels);
	}//end testAnUnresolvableCalendarKeepsIsoWeeks()
}//end class
