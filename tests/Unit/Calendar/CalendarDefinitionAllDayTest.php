<?php

/**
 * Characterisation: how CalendarDefinition decides an event is all-day.
 *
 * Pins each branch of allDay() and the dtstart format lookup behind it: the
 * explicit setting, no dtstart field, the property found by name or by title,
 * the first matching property winning, and non-date formats being ignored.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Calendar;

use OCA\OpenRegister\Calendar\CalendarDefinition;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden allDay() outcomes.
 */
class CalendarDefinitionAllDayTest extends TestCase {

	/**
	 * One case per branch.
	 *
	 * @return array<string, array{0: array, 1: array, 2: bool}>
	 */
	public static function cases(): array {
		$dateTime = ['type' => 'string', 'format' => 'date-time'];
		$date     = ['type' => 'string', 'format' => 'date'];
		return [
			'explicit false wins'         => [['start' => $dateTime], ['dtstart' => 'start', 'allDay' => 0], false],
			'explicit true wins'          => [['start' => $dateTime], ['dtstart' => 'start', 'allDay' => 'yes'], true],
			'no dtstart field'            => [['start' => $dateTime], [], true],
			'by name, date-time'          => [['start' => $dateTime], ['dtstart' => 'start'], false],
			'by name, date'               => [['start' => $date], ['dtstart' => 'start'], true],
			'by title, date-time'         => [['begin' => $dateTime + ['title' => 'Start']], ['dtstart' => 'Start'], false],
			'other format ignored'        => [['start' => ['type' => 'string', 'format' => 'email']], ['dtstart' => 'start'], true],
			'not an array ignored'        => [['start' => 'date-time'], ['dtstart' => 'start'], true],
			'no match'                    => [['end' => $dateTime], ['dtstart' => 'start'], true],
			'first match wins'            => [['start' => $date, 'other' => $dateTime + ['title' => 'start']], ['dtstart' => 'start'], true],
			'skips a non-date first'      => [['start' => ['format' => 'uri'], 'other' => $dateTime + ['title' => 'start']], ['dtstart' => 'start'], false],
			'no properties'               => [[], ['dtstart' => 'start'], true],
		];
	}//end cases()

	/**
	 * allDay() matches the golden outcome.
	 *
	 * @param array $properties The schema properties.
	 * @param array $config     The calendar config.
	 * @param bool  $expected   Whether the event is all-day.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testAllDayMatchesTheGolden(array $properties, array $config, bool $expected): void {
		$schema = new Schema();
		$schema->setProperties($properties);

		$this->assertSame($expected, (new CalendarDefinition(schema: $schema, config: $config))->allDay());
	}//end testAllDayMatchesTheGolden()
}//end class
